<?php

namespace Tests\Feature\System;

use App\Domain\Import\ImportRunner;
use App\Domain\Import\PatientImport;
use App\Domain\Messaging\MessageDispatcher;
use App\Models\Appointment;
use App\Models\ImportRun;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

/** MIG-01…05, T09: mapping, simulation, repeatable and resumable imports, reconciliation. */
class ImportTest extends TestCase
{
    use RefreshDatabase, SchedulingFixtures;

    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $run) {
            File::deleteDirectory(storage_path('app/private/'.$run->file_path));
        }
        parent::tearDown();
    }

    private static function cnp(string $first12): string
    {
        $key = '279146358279';
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $first12[$i] * (int) $key[$i];
        }

        return $first12.($sum % 11 === 10 ? 1 : $sum % 11);
    }

    private function csv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp');
        $out = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($out, $row, ';');
        }
        fclose($out);

        return $path;
    }

    private function upload(array $rows, string $target = 'patients', string $name = 'export.csv'): ImportRun
    {
        $run = app(ImportRunner::class)->upload($this->csv($rows), $name, $target, 'evolvo', null);
        $this->created[] = $run;

        return $run;
    }

    private function runAll(ImportRun $run, int $batch = 2): ImportRun
    {
        $runner = app(ImportRunner::class);
        $runner->simulate($run);
        $runner->start($run->refresh());
        for ($i = 0; $i < 100 && $run->refresh()->status === 'running'; $i++) {
            $runner->runBatch($run, $batch);
        }

        return $run->refresh();
    }

    private function patientRows(): array
    {
        return [
            ['ID Pacient', 'Nume', 'Prenume', 'Data nașterii', 'Telefon', 'Email', 'CNP', 'Limba'],
            ['1001', 'Fictivescu', 'Ana', '12.03.1980', '0740 000 001', 'ana@example.test', self::cnp('280031212345'), 'romana'],
            ['1002', 'Demo', 'Béla', '1975-07-01', '+40 740 000 002', 'nu-e-email', '1234567890123', 'maghiara'],
            ['1003', 'Test', '', '', '', '', '', ''],
            ['1001', 'Fictivescu', 'Ana', '', '', '', '', ''],
        ];
    }

    public function test_columns_are_mapped_from_export_headers(): void
    {
        $run = $this->upload($this->patientRows());
        $this->assertSame(['external_ref', 'last_name', 'first_name', 'birth_date', 'phone', 'email', 'cnp', 'locale'], array_values($run->mapping));
        $this->assertSame(4, $run->total_rows);
        $this->assertSame([], app(ImportRunner::class)->missingFields($run));
    }

    public function test_simulation_writes_nothing_and_import_matches_it(): void
    {
        $run = $this->upload($this->patientRows());
        $stats = app(ImportRunner::class)->simulate($run);
        $this->assertSame(0, Patient::count());
        $this->assertSame(['created' => 2, 'updated' => 0, 'linked' => 0, 'unchanged' => 0, 'duplicate' => 1, 'error' => 1], $stats);

        $run = $this->runAll($run);
        $this->assertSame('done', $run->status);
        $this->assertSame(2, Patient::count());
        $ana = Patient::where('external_ref', 'evolvo:1001')->first();
        $this->assertSame('1980-03-12', $ana->birth_date->toDateString());
        $this->assertSame(self::cnp('280031212345'), $ana->cnp());
        $bela = Patient::where('external_ref', 'evolvo:1002')->first();
        $this->assertSame('hu', $bela->locale);
        $this->assertNull($bela->email);
        $this->assertNull($bela->cnp_hash);
        $messages = collect($run->issues)->flatMap(fn ($i) => $i['messages'])->all();
        $this->assertContains('email_invalid', $messages);
        $this->assertContains('cnp_invalid', $messages);
        $this->assertContains('name_missing', $messages);

        $rec = app(ImportRunner::class)->reconciliation($run);
        $this->assertSame(4, $rec['rows']);
        $this->assertSame(4, $rec['accounted']);
        $this->assertSame(2, $rec['in_database']);
    }

    public function test_repeated_import_never_duplicates_and_never_overwrites_staff_changes(): void
    {
        $this->runAll($this->upload($this->patientRows()));
        $ana = Patient::where('external_ref', 'evolvo:1001')->first();
        $ana->update(['phone' => '0744 999 999']);
        $bela = Patient::where('external_ref', 'evolvo:1002')->first();

        $rows = $this->patientRows();
        $rows[2][5] = 'bela@example.test';
        $run = $this->runAll($this->upload($rows, name: 'export-2.csv'));

        $this->assertSame(2, Patient::count());
        $this->assertSame('0744 999 999', $ana->fresh()->phone);
        // The empty field is completed.
        $this->assertSame('bela@example.test', $bela->fresh()->email);
        $this->assertSame(1, $run->stats['updated']);
        $this->assertSame(1, $run->stats['unchanged']);
    }

    public function test_possible_duplicates_are_reported_and_same_cnp_is_linked(): void
    {
        $existing = Patient::create(['code' => 'P000777', 'first_name' => 'Ana', 'last_name' => 'Fictivescu', 'phone' => '0740000001', 'locale' => 'ro']);
        $sameCnp = Patient::create(['code' => 'P000778', 'first_name' => 'Radu', 'last_name' => 'Exemplu', 'locale' => 'ro']);
        $cnp = self::cnp('180010112345');
        $sameCnp->setCnp($cnp);
        $sameCnp->save();

        $run = $this->runAll($this->upload([
            ['id', 'nume', 'prenume', 'telefon', 'cnp'],
            ['2001', 'Fictivescu', 'Ana', '0740 000 001', ''],
            ['2002', 'Exemplu', 'Radu', '', $cnp],
        ]));

        $this->assertSame(1, $run->stats['duplicate']);
        $this->assertSame(1, $run->stats['linked']);
        $this->assertNull($existing->fresh()->external_ref);
        $this->assertSame('evolvo:2002', $sameCnp->fresh()->external_ref);
        $this->assertSame(2, Patient::count());
    }

    public function test_an_interrupted_batch_resumes_without_duplicates(): void
    {
        $rows = [['id', 'nume', 'prenume']];
        for ($i = 1; $i <= 7; $i++) {
            $rows[] = ['30'.$i, 'Pacient', 'Demo'.$i];
        }
        $run = $this->upload($rows);
        $runner = app(ImportRunner::class);
        $runner->simulate($run);
        $runner->start($run->refresh());
        $runner->runBatch($run, 3);
        $this->assertSame(3, $run->refresh()->cursor);

        // A batch that dies halfway leaves nothing behind: rows and cursor are committed together.
        Patient::creating(function (Patient $p) {
            if ($p->first_name === 'Demo5') {
                throw new \RuntimeException('server stopped');
            }
        });
        $runner->runBatch($run, 3);
        $run->refresh();
        $this->assertSame(6, $run->cursor);
        $this->assertSame(1, $run->stats['error']);
        Patient::flushEventListeners();
        Patient::boot();

        $this->runAll($run);
        $this->assertSame(6, Patient::where('external_ref', 'like', 'evolvo:%')->count());
        $this->assertSame(1, Patient::where('first_name', 'Demo4')->count());
    }

    public function test_appointments_keep_their_relations_and_never_send_retroactive_messages(): void
    {
        $location = $this->makeLocation(['code' => 'TGM-REP']);
        $specialist = $this->makeSpecialist(['display_name' => 'Dr. Exemplu Demo']);
        $service = $this->makeService(['content' => ['ro' => ['name' => 'Consultație oftalmologică'], 'hu' => ['name' => 'Szemészeti vizsgálat']], 'default_duration' => 20]);
        $this->runAll($this->upload([['id', 'nume', 'prenume'], ['1001', 'Fictivescu', 'Ana']]));

        $run = $this->runAll($this->upload([
            ['ID programare', 'ID pacient', 'Locație', 'Medic', 'Serviciu', 'Data', 'Ora', 'Status'],
            ['A1', '1001', 'TGM-REP', 'dr. exemplu demo', 'Consultatie oftalmologica', '10.01.2026', '09:30', 'Finalizat'],
            ['A2', '1001', 'TGM-REP', 'Dr. Exemplu Demo', 'Consultație oftalmologică', '15.03.2027', '10:00', 'Programat'],
            ['A3', '9999', 'TGM-REP', 'Dr. Exemplu Demo', 'Consultație oftalmologică', '15.03.2027', '11:00', ''],
            ['A4', '1001', 'Altă locație', 'Dr. Nimeni', 'Consultație oftalmologică', '15.03.2027', '11:00', ''],
        ], 'appointments'));

        $this->assertSame(2, $run->stats['created']);
        $this->assertSame(2, $run->stats['error']);
        $a1 = Appointment::where('external_ref', 'evolvo:A1')->first();
        $this->assertSame('completed', $a1->status->value);
        $this->assertSame('09:30', $a1->starts_at->setTimezone('Europe/Bucharest')->format('H:i'));
        $this->assertSame(20, (int) $a1->starts_at->diffInMinutes($a1->ends_at));
        $this->assertSame(Patient::where('external_ref', 'evolvo:1001')->value('id'), $a1->patient_id);
        $this->assertTrue($a1->notifications_disabled);
        $this->assertTrue(Appointment::where('external_ref', 'evolvo:A2')->value('notifications_disabled'));
        $this->assertNull(app(MessageDispatcher::class)->forAppointment($a1, 'appointment_confirmed'));
        $messages = collect($run->issues)->flatMap(fn ($i) => $i['messages'])->all();
        $this->assertContains('patient_unknown', $messages);
        $this->assertContains('specialist_unknown', $messages);

        // Repeating the appointment import adds nothing.
        $again = $this->runAll($this->upload([
            ['ID programare', 'ID pacient', 'Locație', 'Medic', 'Serviciu', 'Data', 'Ora'],
            ['A1', '1001', 'TGM-REP', 'Dr. Exemplu Demo', 'Consultație oftalmologică', '10.01.2026', '09:30'],
        ], 'appointments'));
        $this->assertSame(1, $again->stats['unchanged']);
        $this->assertSame(2, Appointment::count());
    }

    public function test_cnp_control_digit(): void
    {
        $this->assertTrue(PatientImport::validCnp(self::cnp('180010112345')));
        $this->assertFalse(PatientImport::validCnp('1800101123450'.'1'));
        $this->assertFalse(PatientImport::validCnp('0800101123456'));
    }

    public function test_import_screen_requires_its_permission(): void
    {
        $this->signIn($this->staff('reception'))->get(route('admin.system.import'))->assertForbidden();
        $admin = $this->staff('superadmin', ['all_locations' => true]);
        $this->signIn($admin)->get(route('admin.system.import'))->assertOk()->assertSee(__('system.import.new'));
        $run = $this->upload($this->patientRows());
        $this->get(route('admin.system.import.report', $run->uuid))->assertOk();
        $this->signIn($this->staff('reception'))->get(route('admin.system.import.report', $run->uuid))->assertForbidden();
        $this->assertInstanceOf(User::class, $admin);
    }
}
