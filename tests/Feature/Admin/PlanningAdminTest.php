<?php

namespace Tests\Feature\Admin;

use App\Domain\Scheduling\AvailabilityService;
use App\Domain\Scheduling\BookingService;
use App\Enums\AppointmentSource;
use App\Livewire\Admin\ExceptionIndex;
use App\Livewire\Admin\LocationEditor;
use App\Livewire\Admin\OfferingEditor;
use App\Livewire\Admin\ResourceManager;
use App\Livewire\Admin\ScheduleEditor;
use App\Models\LocationSpecialist;
use App\Models\Offering;
use App\Models\Schedule;
use App\Models\ScheduleException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class PlanningAdminTest extends TestCase
{
    use RefreshDatabase, SchedulingFixtures;

    private const TZ = 'Europe/Bucharest';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00', self::TZ));
    }

    private function slots(Offering $offering, string $date = '2026-11-02', bool $online = true): array
    {
        $day = CarbonImmutable::parse($date, self::TZ);

        return array_map(fn ($s) => $s['starts_at']->setTimezone(self::TZ)->format('H:i'),
            app(AvailabilityService::class)->slots($offering->fresh(), $day, $day, $online));
    }

    public function test_offerings_editor_sets_duration_capacity_resource_and_online_settings(): void
    {
        $admin = $this->staff('operations_admin', ['all_locations' => true]);
        $location = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $service = $this->makeService(['default_duration' => 30]);
        LocationSpecialist::create(['location_id' => $location->id, 'specialist_id' => $specialist->id, 'is_active' => true, 'online_enabled' => true]);

        Livewire::actingAs($admin)->test(ResourceManager::class, ['location' => $location])
            ->set('type', 'oct')->set('name.ro', 'OCT 1')->call('save')->assertHasNoErrors();

        Livewire::actingAs($admin)->test(OfferingEditor::class, ['specialist' => $specialist])
            ->set("rows.{$location->id}.{$service->id}.offered", true)
            ->set("rows.{$location->id}.{$service->id}.duration", 45)
            ->set("rows.{$location->id}.{$service->id}.capacity", 2)
            ->set("rows.{$location->id}.{$service->id}.resource_type", 'oct')
            ->set("rows.{$location->id}.{$service->id}.online", true)
            ->set("online.{$location->id}.min_lead_hours", 24)
            ->set("online.{$location->id}.max_advance_days", 30)
            ->set("online.{$location->id}.pause_hu", 'Szabadságon vagyok')
            ->call('save')
            ->assertHasNoErrors();

        $offering = Offering::firstWhere(['specialist_id' => $specialist->id, 'service_id' => $service->id]);
        $this->assertSame(45, $offering->durationMinutes());
        $this->assertSame(2, $offering->capacity);
        $this->assertSame('oct', $offering->resource_type);
        $this->assertTrue($offering->online_enabled);
        $link = LocationSpecialist::firstWhere('specialist_id', $specialist->id);
        $this->assertSame(1440, $link->min_lead_minutes);
        $this->assertSame('Szabadságon vagyok', $link->content['hu']['pause_message']);

        // Withdrawing keeps the row (history) but stops booking.
        Livewire::actingAs($admin)->test(OfferingEditor::class, ['specialist' => $specialist])
            ->set("rows.{$location->id}.{$service->id}.offered", false)
            ->call('save');
        $this->assertFalse($offering->fresh()->is_active);
        $this->assertFalse($offering->fresh()->online_enabled);
    }

    public function test_schedule_editor_creates_schedule_that_drives_availability(): void
    {
        $admin = $this->staff('operations_admin', ['all_locations' => true]);
        $location = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $offering = $this->makeOffering($location, $specialist, $this->makeService(['default_duration' => 30]));

        $component = Livewire::actingAs($admin)->test(ScheduleEditor::class)
            ->set('specialist_id', $specialist->id)
            ->set('location_id', $location->id)
            ->set('name', 'Program toamnă')
            ->set('valid_from', '2026-11-01')
            ->call('addSegment', 1, 1);
        $component->set('segments.0.start_time', '09:00')->set('segments.0.end_time', '10:00')
            ->call('copyDay', 1, 1)
            ->call('save')
            ->assertHasNoErrors();

        $schedule = Schedule::firstOrFail();
        $this->assertSame(5, $schedule->segments()->count());
        $this->assertSame(['09:00', '09:15', '09:30'], $this->slots($offering));
        $this->assertSame(['09:00', '09:15', '09:30'], $this->slots($offering, '2026-11-06'));
        $this->assertSame([], $this->slots($offering, '2026-11-07'));
    }

    public function test_schedule_editor_rejects_overlapping_segments_and_unlinked_location(): void
    {
        $admin = $this->staff('operations_admin', ['all_locations' => true]);
        $location = $this->makeLocation();
        $other = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $this->makeOffering($location, $specialist, $this->makeService());

        Livewire::actingAs($admin)->test(ScheduleEditor::class)
            ->set('specialist_id', $specialist->id)->set('location_id', $other->id)
            ->set('name', 'X')->set('valid_from', '2026-11-01')
            ->call('save')
            ->assertHasErrors('location_id');

        Livewire::actingAs($admin)->test(ScheduleEditor::class)
            ->set('specialist_id', $specialist->id)->set('location_id', $location->id)
            ->set('name', 'X')->set('valid_from', '2026-11-01')
            ->set('segments', [
                ['cycle_week' => 1, 'weekday' => 1, 'segment' => 'before_noon', 'start_time' => '09:00', 'end_time' => '12:00', 'slot_minutes' => 15, 'online_enabled' => true, 'service_ids' => [], 'resource_id' => null],
                ['cycle_week' => 1, 'weekday' => 1, 'segment' => 'afternoon', 'start_time' => '11:00', 'end_time' => '13:00', 'slot_minutes' => 15, 'online_enabled' => true, 'service_ids' => [], 'resource_id' => null],
            ])
            ->call('save')
            ->assertHasErrors('segments.1.start_time');
        $this->assertSame(0, Schedule::count());
    }

    public function test_schedule_change_reports_affected_appointments_without_deleting_them(): void
    {
        $admin = $this->staff('operations_admin', ['all_locations' => true]);
        $location = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $offering = $this->makeOffering($location, $specialist, $this->makeService(['default_duration' => 30]));
        $schedule = $this->makeSchedule($location, $specialist, [1 => [['09:00', '12:00']]]);
        $appointment = app(BookingService::class)->book($offering, ['starts_at' => CarbonImmutable::parse('2026-11-02 11:00', self::TZ), 'source' => AppointmentSource::Reception]);

        Livewire::actingAs($admin)->test(ScheduleEditor::class, ['schedule' => $schedule])
            ->set('segments.0.end_time', '10:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertStringContainsString('nu mai încape', session('status'));
        $this->assertNotNull($appointment->fresh());
        $this->assertSame('confirmed', $appointment->fresh()->status->value);
        $this->signIn($admin)->get('/admin/schedules')->assertSee('O programare viitoare nu mai încape în program');
    }

    public function test_exception_blocks_time_and_reports_affected(): void
    {
        $admin = $this->staff('operations_admin', ['all_locations' => true]);
        $location = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $offering = $this->makeOffering($location, $specialist, $this->makeService(['default_duration' => 60]));
        $this->makeSchedule($location, $specialist, [1 => [['09:00', '12:00']], 2 => [['09:00', '12:00']]]);
        app(BookingService::class)->book($offering, ['starts_at' => CarbonImmutable::parse('2026-11-03 09:00', self::TZ), 'source' => AppointmentSource::Reception]);

        Livewire::actingAs($admin)->test(ExceptionIndex::class)
            ->set('specialist_id', $specialist->id)
            ->set('reason', 'leave')
            ->set('starts_on', '2026-11-03')->set('ends_on', '2026-11-03')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, ScheduleException::count());
        $this->assertSame([], $this->slots($offering, '2026-11-03'));
        $this->assertStringContainsString('nu mai încape', session('status'));

        // Extra availability needs a specialist, location and hours.
        Livewire::actingAs($admin)->test(ExceptionIndex::class)
            ->set('kind', 'available')->set('starts_on', '2026-11-07')->set('ends_on', '2026-11-07')
            ->call('save')
            ->assertHasErrors('kind');
    }

    public function test_location_manager_cannot_touch_other_locations_or_global_exceptions(): void
    {
        $mine = $this->makeLocation();
        $other = $this->makeLocation();
        $manager = $this->staff('location_manager', [], [$mine->id]);
        $specialist = $this->makeSpecialist();
        $this->makeOffering($other, $specialist, $this->makeService());
        $schedule = $this->makeSchedule($other, $specialist, [1 => [['09:00', '12:00']]]);

        Livewire::actingAs($manager)->test(ScheduleEditor::class, ['schedule' => $schedule])->assertForbidden();
        Livewire::actingAs($manager)->test(ExceptionIndex::class)
            ->set('reason', 'holiday')->set('location_id', null)
            ->call('save')
            ->assertForbidden();
        $this->assertSame(0, ScheduleException::count());

        Livewire::actingAs($manager)->test(ExceptionIndex::class)
            ->set('reason', 'closure')->set('location_id', $mine->id)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(1, ScheduleException::count());
    }

    public function test_location_editor_shows_resources_section(): void
    {
        $admin = $this->staff('operations_admin', ['all_locations' => true]);
        $location = $this->makeLocation();

        Livewire::actingAs($admin)->test(LocationEditor::class, ['location' => $location])
            ->assertSee('Cabinete și aparatură');
        $this->signIn($admin)->get('/admin/schedules')->assertOk();
        $this->signIn($admin)->get('/admin/exceptions')->assertOk();
    }
}
