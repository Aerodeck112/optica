<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\ClinicalAccess;
use App\Domain\Clinical\ClinicalException;
use App\Domain\Clinical\RecordService;
use App\Models\AuditLog;
use App\Models\ClinicalRecord;
use App\Models\ClinicalTemplate;
use Database\Seeders\ClinicalTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ClinicalFixtures;
use Tests\TestCase;

class ClinicalRecordsTest extends TestCase
{
    use ClinicalFixtures, RefreshDatabase;

    private function service(): RecordService
    {
        return app(RecordService::class);
    }

    public function test_an_unvalidated_template_cannot_be_used(): void
    {
        $this->seed(ClinicalTemplateSeeder::class);
        $location = $this->makeLocation();
        $doctor = $this->doctor($location);

        $this->expectException(ClinicalException::class);
        $this->service()->createDraft($this->makePatient(), ClinicalTemplate::firstOrFail(), $doctor, $location->id);
    }

    public function test_draft_then_final_then_amendment_keeps_the_history(): void
    {
        $template = $this->validatedTemplate();
        $location = $this->makeLocation();
        $doctor = $this->doctor($location);
        $record = $this->service()->createDraft($this->makePatient(), $template, $doctor, $location->id);

        // Drafts can be incomplete; ranges are still checked.
        $record = $this->service()->saveDraft($record, ['reason' => 'Doar motivul (DEMO)'], $record->lock_version, $doctor);
        try {
            $this->service()->finalize($record, $record->data, $record->lock_version, $doctor);
            $this->fail('Required diagnosis was not enforced');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('data.diagnosis', $e->errors());
        }

        $record = $this->service()->finalize($record, $this->filledData(), $record->lock_version, $doctor);
        $this->assertTrue($record->isFinal());
        $this->assertSame('-1.25', $record->data['sph']['od']);
        $this->assertSame('0.5', $record->data['sph']['os']);

        // After finalization the record is never saved as a draft again.
        $this->expectExceptionObject(new ClinicalException(__('clinical.errors.already_final')));
        try {
            $this->service()->saveDraft($record, $this->filledData(), $record->lock_version, $doctor);
        } finally {
            $data = $this->filledData();
            $data['sph']['od'] = '-1.5';
            $amendment = $this->service()->amend($record, $data, 'Valoare transcrisă greșit (DEMO)', $doctor);
            $this->assertSame(['sph' => ['old' => ['od' => '-1.25', 'os' => '0.5'], 'new' => ['od' => '-1.5', 'os' => '0.5']]], $amendment->changes);
            $this->assertSame('-1.5', $record->fresh()->data['sph']['od']);
            $this->assertSame(1, $record->amendments()->count());
        }
    }

    public function test_amendments_need_a_reason_and_a_real_change(): void
    {
        $template = $this->validatedTemplate();
        $location = $this->makeLocation();
        $doctor = $this->doctor($location);
        $record = $this->service()->createDraft($this->makePatient(), $template, $doctor, $location->id);
        $record = $this->service()->finalize($record, $this->filledData(), $record->lock_version, $doctor);

        foreach ([['', $this->filledData()], ['Motiv valid DEMO', $this->filledData()]] as [$reason, $data]) {
            try {
                $this->service()->amend($record, $data, $reason, $doctor);
                $this->fail('Amendment accepted');
            } catch (ClinicalException) {
                $this->assertSame(0, $record->amendments()->count());
            }
        }
    }

    public function test_values_out_of_range_or_off_step_are_rejected(): void
    {
        $template = $this->validatedTemplate();
        $location = $this->makeLocation();
        $doctor = $this->doctor($location);
        $record = $this->service()->createDraft($this->makePatient(), $template, $doctor, $location->id);

        try {
            $this->service()->saveDraft($record, ['sph' => ['od' => '-1.3', 'os' => '45'], 'axis' => ['od' => '200', 'os' => '']], $record->lock_version, $doctor);
            $this->fail('Invalid values accepted');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['data.sph.od', 'data.sph.os', 'data.axis.od'], array_keys($e->errors()));
        }
    }

    public function test_two_windows_cannot_overwrite_the_same_draft(): void
    {
        $template = $this->validatedTemplate();
        $location = $this->makeLocation();
        $doctor = $this->doctor($location);
        $record = $this->service()->createDraft($this->makePatient(), $template, $doctor, $location->id);
        $version = $record->lock_version;

        $this->service()->saveDraft($record, ['reason' => 'Fereastra 1'], $version, $doctor);
        $this->expectException(ClinicalException::class);
        $this->service()->saveDraft($record, ['reason' => 'Fereastra 2'], $version, $doctor);
    }

    public function test_clinical_data_is_encrypted_at_rest_and_kept_out_of_the_audit_log(): void
    {
        $template = $this->validatedTemplate();
        $location = $this->makeLocation();
        $doctor = $this->doctor($location);
        $record = $this->service()->createDraft($this->makePatient(), $template, $doctor, $location->id);
        $this->service()->finalize($record, $this->filledData(), $record->lock_version, $doctor);

        $raw = DB::table('clinical_records')->where('id', $record->id)->value('data');
        $this->assertStringNotContainsString('DEMO', $raw);
        $this->assertStringNotContainsString('DEMO', AuditLog::pluck('meta')->filter()->map(fn ($m) => json_encode($m))->implode(' '));
    }

    public function test_who_can_see_records(): void
    {
        $template = $this->validatedTemplate();
        $location = $this->makeLocation();
        $other = $this->makeLocation();
        $doctor = $this->doctor($location);
        $colleague = $this->doctor($location);
        $patient = $this->makePatient();
        $record = $this->service()->createDraft($patient, $template, $doctor, $location->id);
        $access = app(ClinicalAccess::class);

        $this->assertTrue($access->canViewRecord($doctor, $record));
        // A colleague sees the record only once the patient is also theirs.
        $this->assertFalse($access->canViewRecord($colleague, $record));
        $this->appointmentFor($patient, $colleague->specialist, $location);
        $this->assertTrue($access->canViewRecord($colleague, $record));
        $this->assertFalse($access->canEditDraft($colleague, $record));

        // Superadmin and reception have no implicit clinical access.
        $this->assertFalse($access->canViewRecord($this->staff('superadmin'), $record));
        $this->assertFalse($access->canViewRecord($this->staff('reception', [], [$location->id]), $record));

        // "view" is limited to the user's locations.
        $chief = $this->staffWithLocations(['medical_records.view'], [$other->id]);
        $this->assertFalse($access->canViewRecord($chief, $record));
        $chief->locations()->attach($location->id);
        app(\App\Support\Access\AccessControl::class)->flush();
        $this->assertTrue($access->canViewRecord($chief->fresh(), $record));
        $this->assertSame(1, $access->scopeRecords(ClinicalRecord::query(), $chief->fresh(), $patient)->count());
        $this->assertSame(0, $access->scopeRecords(ClinicalRecord::query(), $this->staff('superadmin'), $patient)->count());
    }

    private function staffWithLocations(array $permissions, array $locations)
    {
        $user = $this->staff('reception', [], $locations);
        $role = \App\Models\Role::create(['key' => 'chief-'.uniqid(), 'name' => ['ro' => 'Test', 'hu' => 'Test']]);
        $role->permissions()->sync(\App\Models\Permission::whereIn('key', $permissions)->pluck('id'));
        $user->roles()->sync([$role->id]);
        app(\App\Support\Access\AccessControl::class)->flush();

        return $user->fresh();
    }
}
