<?php

namespace Tests\Feature\Reports;

use App\Jobs\ExportReport;
use App\Livewire\Crm\Reports;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\ChannelAccount;
use App\Models\ReportExport;
use App\Models\ReportSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\ClinicalFixtures;
use Tests\TestCase;

class ReportScreensTest extends TestCase
{
    use ClinicalFixtures, RefreshDatabase;

    private function seedAppointments(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00', 'Europe/Bucharest'));
        $location = $this->makeLocation();
        $doctor = $this->makeSpecialist();
        $service = $this->makeService();
        foreach (['completed', 'cancelled', 'confirmed'] as $i => $status) {
            $start = CarbonImmutable::parse('2026-11-0'.($i + 3).' 10:00', 'Europe/Bucharest');
            Appointment::create(['uuid' => (string) Str::uuid(), 'patient_id' => $this->makePatient(['last_name' => '=CMD'])->id, 'location_id' => $location->id,
                'specialist_id' => $doctor->id, 'service_id' => $service->id, 'starts_at' => $start, 'ends_at' => $start->addMinutes(30),
                'block_starts_at' => $start, 'block_ends_at' => $start->addMinutes(30), 'status' => $status, 'source' => 'online', 'locale' => 'ro']);
        }

        return [$location, $doctor];
    }

    public function test_every_view_renders_and_marketing_sees_no_money_or_names(): void
    {
        [$location] = $this->seedAppointments();
        $this->signIn($this->staff('superadmin', ['all_locations' => true]));
        $component = Livewire::test(Reports::class)->set('filters.from', '2026-11-01')->set('filters.to', '2026-11-30');
        foreach (['summary', 'locations', 'specialists', 'services', 'matrix', 'occupancy', 'trend', 'distribution', 'financial', 'sources', 'records'] as $tab) {
            $component->set('tab', $tab)->assertOk();
        }
        $component->set('tab', 'summary')->assertSee('33,3 %');

        $this->signIn($this->staff('marketing', ['all_locations' => true]));
        Livewire::test(Reports::class)->set('tab', 'financial')->assertSet('tab', 'summary');
        Livewire::test(Reports::class)->set('filters.from', '2026-11-01')->set('tab', 'records')->assertDontSee('=CMD')->assertSee('P9');

        $this->signIn($this->staff('reception'));
        $this->get(route('crm.reports'))->assertForbidden();
    }

    public function test_drill_down_moves_from_location_to_specialists_to_records(): void
    {
        [$location, $doctor] = $this->seedAppointments();
        $this->signIn($this->staff('superadmin', ['all_locations' => true]));
        Livewire::test(Reports::class)->set('filters.from', '2026-11-01')->set('tab', 'locations')
            ->call('drill', 'location', $location->id)->assertSet('tab', 'specialists')->assertSet('filters.locations', [$location->id])
            ->call('drill', 'specialist', $doctor->id)->assertSet('tab', 'records');
    }

    public function test_exports_run_in_the_background_are_private_and_safe_in_spreadsheets(): void
    {
        $this->seedAppointments();
        Storage::fake('local');
        Queue::fake();
        $user = $this->staff('superadmin', ['all_locations' => true]);
        $this->signIn($user);
        Livewire::test(Reports::class)->set('filters.from', '2026-11-01')->set('tab', 'records')->call('export', 'xlsx')->call('export', 'csv');
        Queue::assertPushed(ExportReport::class, 2);

        foreach (ReportExport::all() as $export) {
            (new ExportReport($export->id))->handle(app(\App\Domain\Reports\ReportTables::class), app(\App\Domain\Reports\ReportWriter::class), app(\App\Jobs\ReportFilterDescriber::class));
        }
        $csv = ReportExport::where('format', 'csv')->firstOrFail();
        $this->assertSame('done', $csv->status);
        $this->assertSame(3, $csv->rows);
        $content = Storage::disk('local')->get($csv->path);
        $this->assertStringContainsString("'=CMD", $content);
        $this->assertStringContainsString('Perioada 01.11.2026', $content);

        $xlsx = ReportExport::where('format', 'xlsx')->firstOrFail();
        $reader = new Reader;
        $reader->open(Storage::disk('local')->path($xlsx->path));
        $cells = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells[] = $row->toArray();
            }
        }
        $reader->close();
        $this->assertSame('Programări (detaliu)', $cells[0][0]);

        $this->get(route('crm.reports.download', $csv->uuid))->assertOk()->assertDownload();
        $this->assertTrue(AuditLog::where('action', 'reports.downloaded')->exists());

        // Another user cannot download it, even with the link.
        $this->signIn($this->staff('superadmin', ['all_locations' => true]));
        $this->get(route('crm.reports.download', $csv->uuid))->assertNotFound();
    }

    public function test_scheduled_reports_are_not_sent_without_an_email_account(): void
    {
        $user = $this->staff('location_manager');
        $sub = ReportSubscription::create(['name' => 'Săptămânal', 'report' => 'summary', 'format' => 'pdf', 'locale' => 'ro', 'frequency' => 'daily',
            'filters' => [], 'recipients' => ['manager@example.test'], 'created_by' => $user->id]);
        $this->artisan('reports:send-scheduled')->assertSuccessful();
        $this->assertStringContainsString('nu este configurat', $sub->fresh()->last_result);

        // Rights are those of the author at sending time.
        $marketing = $this->staff('marketing');
        $financial = ReportSubscription::create(['name' => 'Bani', 'report' => 'financial', 'format' => 'csv', 'locale' => 'ro', 'frequency' => 'daily',
            'filters' => [], 'recipients' => ['x@example.test'], 'created_by' => $marketing->id]);
        ChannelAccount::create(['channel' => 'email', 'driver' => 'smtp', 'name' => 'Mail', 'sender' => 'noreply@example.test', 'is_active' => true,
            'credentials' => ['host' => '127.0.0.1', 'port' => 2525, 'username' => 'u', 'password' => 'p']]);
        $this->artisan('reports:send-scheduled', ['--force' => true])->assertSuccessful();
        $this->assertSame('forbidden', $financial->fresh()->last_result);
    }

    public function test_subscriptions_validate_recipients(): void
    {
        $this->signIn($this->staff('superadmin', ['all_locations' => true]));
        Livewire::test(Reports::class)->set('showSubscriptions', true)
            ->set('subscription.name', 'Zilnic')->set('subscription.recipients', 'a@example.test, nu-este-email')->call('subscribe')
            ->assertHasErrors(['subscription.recipients'])
            ->set('subscription.recipients', 'a@example.test; b@example.test')->call('subscribe')->assertHasNoErrors();
        $this->assertSame(['a@example.test', 'b@example.test'], ReportSubscription::firstOrFail()->recipients);
    }
}
