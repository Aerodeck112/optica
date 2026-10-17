<?php

namespace Tests\Feature\Scheduling;

use App\Domain\Scheduling\AvailabilityService;
use App\Models\LocationSpecialist;
use App\Models\ScheduleException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase, SchedulingFixtures;

    private const TZ = 'Europe/Bucharest';

    protected function setUp(): void
    {
        parent::setUp();
        // Monday 2 November 2026, 07:00 local (winter time, UTC+2).
        $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00', self::TZ));
    }

    private function localTimes(array $slots): array
    {
        return array_map(fn ($s) => $s['starts_at']->setTimezone(self::TZ)->format('Y-m-d H:i'), $slots);
    }

    private function service(): AvailabilityService
    {
        return app(AvailabilityService::class);
    }

    public function test_slots_follow_segments_grid_and_duration(): void
    {
        $location = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $offering = $this->makeOffering($location, $specialist, $this->makeService(['default_duration' => 30]));
        $this->makeSchedule($location, $specialist, [1 => [['09:00', '11:00'], ['14:00', '15:00']]]);

        $day = CarbonImmutable::parse('2026-11-02', self::TZ);
        $slots = $this->localTimes($this->service()->slots($offering, $day, $day));

        $this->assertSame([
            '2026-11-02 09:00', '2026-11-02 09:15', '2026-11-02 09:30', '2026-11-02 09:45',
            '2026-11-02 10:00', '2026-11-02 10:15', '2026-11-02 10:30',
            '2026-11-02 14:00', '2026-11-02 14:15', '2026-11-02 14:30',
        ], $slots);

        // Stored/compared in UTC: 09:00 local in November is 07:00 UTC.
        $first = $this->service()->slots($offering, $day, $day)[0]['starts_at'];
        $this->assertSame('2026-11-02 07:00', $first->utc()->format('Y-m-d H:i'));
    }

    public function test_alternating_weeks_use_the_right_cycle(): void
    {
        $location = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $offering = $this->makeOffering($location, $specialist, $this->makeService(['default_duration' => 60]));
        // Anchor week (week 1) starts Monday 2026-11-02: mornings; week 2: afternoons.
        $schedule = $this->makeSchedule($location, $specialist, [1 => [['09:00', '10:00']]], [
            'cycle_weeks' => 2, 'cycle_anchor_date' => '2026-11-04',
        ], cycleWeek: 1);
        $this->addSegments($schedule, [1 => [['15:00', '16:00']]], cycleWeek: 2);

        $week1 = CarbonImmutable::parse('2026-11-02', self::TZ);
        $week2 = CarbonImmutable::parse('2026-11-09', self::TZ);
        $week3 = CarbonImmutable::parse('2026-11-16', self::TZ);

        $this->assertSame(['2026-11-02 09:00'], $this->localTimes($this->service()->slots($offering, $week1, $week1)));
        $this->assertSame(['2026-11-09 15:00'], $this->localTimes($this->service()->slots($offering, $week2, $week2)));
        $this->assertSame(['2026-11-16 09:00'], $this->localTimes($this->service()->slots($offering, $week3, $week3)));
    }

    public function test_leave_holiday_and_partial_exceptions_remove_time(): void
    {
        $location = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $offering = $this->makeOffering($location, $specialist, $this->makeService(['default_duration' => 60]));
        $this->makeSchedule($location, $specialist, [
            1 => [['09:00', '12:00']], 2 => [['09:00', '12:00']], 3 => [['09:00', '12:00']],
        ]);

        // Personal leave on Monday, global holiday on Tuesday, partial break on Wednesday.
        ScheduleException::create(['specialist_id' => $specialist->id, 'kind' => 'unavailable', 'reason' => 'leave', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-02']);
        ScheduleException::create(['kind' => 'unavailable', 'reason' => 'holiday', 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-03']);
        ScheduleException::create(['specialist_id' => $specialist->id, 'location_id' => $location->id, 'kind' => 'unavailable', 'reason' => 'other', 'starts_on' => '2026-11-04', 'ends_on' => '2026-11-04', 'start_time' => '10:00', 'end_time' => '11:00']);

        $from = CarbonImmutable::parse('2026-11-02', self::TZ);
        $to = CarbonImmutable::parse('2026-11-04', self::TZ);

        $this->assertSame(['2026-11-04 09:00', '2026-11-04 11:00'], $this->localTimes($this->service()->slots($offering, $from, $to)));
    }

    public function test_extra_availability_adds_a_window(): void
    {
        $location = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $offering = $this->makeOffering($location, $specialist, $this->makeService(['default_duration' => 30]));
        $this->makeSchedule($location, $specialist, [1 => [['09:00', '09:30']]]);
        ScheduleException::create(['specialist_id' => $specialist->id, 'location_id' => $location->id, 'kind' => 'available', 'reason' => 'other', 'starts_on' => '2026-11-07', 'ends_on' => '2026-11-07', 'start_time' => '10:00', 'end_time' => '10:30', 'online_enabled' => true]);

        $from = CarbonImmutable::parse('2026-11-02', self::TZ);
        $to = CarbonImmutable::parse('2026-11-08', self::TZ);

        $this->assertSame(['2026-11-02 09:00', '2026-11-07 10:00'], $this->localTimes($this->service()->slots($offering, $from, $to, online: true)));
    }

    public function test_dst_change_keeps_local_hours(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 07:00', self::TZ));
        $location = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $offering = $this->makeOffering($location, $specialist, $this->makeService(['default_duration' => 60]));
        // Saturday before and Sunday of the change (25 Oct 2026, 04:00 → 03:00), plus Monday after.
        $this->makeSchedule($location, $specialist, [6 => [['09:00', '10:00']], 7 => [['09:00', '10:00']], 1 => [['09:00', '10:00']]]);

        $slots = $this->service()->slots($offering, CarbonImmutable::parse('2026-10-24', self::TZ), CarbonImmutable::parse('2026-10-26', self::TZ));

        $this->assertSame(['2026-10-24 09:00', '2026-10-25 09:00', '2026-10-26 09:00'], $this->localTimes($slots));
        $this->assertSame(
            ['2026-10-24 06:00', '2026-10-25 07:00', '2026-10-26 07:00'],
            array_map(fn ($s) => $s['starts_at']->utc()->format('Y-m-d H:i'), $slots),
        );
    }

    public function test_online_slots_respect_segment_and_link_switches(): void
    {
        $location = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $offering = $this->makeOffering($location, $specialist, $this->makeService(['default_duration' => 60]));
        $this->makeSchedule($location, $specialist, [1 => [['09:00', '10:00']]], segmentOverrides: ['online_enabled' => false]);

        $day = CarbonImmutable::parse('2026-11-02', self::TZ);
        $this->assertCount(1, $this->service()->slots($offering, $day, $day));
        $this->assertCount(0, $this->service()->slots($offering, $day, $day, online: true));

        $this->makeSchedule($location, $specialist, [1 => [['11:00', '12:00']]]);
        $this->assertCount(1, $this->service()->slots($offering, $day, $day, online: true));

        LocationSpecialist::query()->update(['online_paused' => true]);
        $this->assertCount(0, $this->service()->slots($offering, $day, $day, online: true));
    }

    public function test_online_lead_time_and_past_slots_are_excluded(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:20', self::TZ));
        $location = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $offering = $this->makeOffering($location, $specialist, $this->makeService(['default_duration' => 30]), linkOverrides: ['min_lead_minutes' => 60]);
        $this->makeSchedule($location, $specialist, [1 => [['09:00', '11:00']]]);

        $day = CarbonImmutable::parse('2026-11-02', self::TZ);
        $this->assertSame(['2026-11-02 09:30', '2026-11-02 09:45', '2026-11-02 10:00', '2026-11-02 10:15', '2026-11-02 10:30'], $this->localTimes($this->service()->slots($offering, $day, $day)));
        $this->assertSame(['2026-11-02 10:30'], $this->localTimes($this->service()->slots($offering, $day, $day, online: true)));
    }
}
