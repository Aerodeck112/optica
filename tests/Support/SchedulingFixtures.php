<?php

namespace Tests\Support;

use App\Models\Location;
use App\Models\LocationSpecialist;
use App\Models\Offering;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\Specialist;
use Illuminate\Support\Str;

/**
 * Fictitious scheduling data (ARC-08, GEN-03): no real names, addresses or patients.
 */
trait SchedulingFixtures
{
    protected function makeLocation(array $overrides = []): Location
    {
        $code = 'DEMO-LOC-'.Str::upper(Str::random(5));

        $content = ['ro' => ['name' => 'Locație demo '.$code], 'hu' => ['name' => 'Demo rendelő '.$code]];

        return Location::create($overrides + [
            'code' => $code,
            'content' => $content,
            'published_content' => $content,
            'published_at' => now(),
            'city' => 'Oraș Demo',
            'address' => 'Strada Demo nr. 1',
            'is_active' => true,
            'is_public' => true,
            'online_booking_enabled' => true,
        ]);
    }

    protected function makeSpecialist(array $overrides = []): Specialist
    {
        $code = 'DEMO-SP-'.Str::upper(Str::random(5));

        return Specialist::create($overrides + [
            'code' => $code,
            'type' => 'ophthalmologist',
            'display_name' => 'Dr. Demo '.$code,
            'content' => ['ro' => ['bio' => ''], 'hu' => ['bio' => '']],
            'published_content' => ['ro' => ['bio' => ''], 'hu' => ['bio' => '']],
            'published_at' => now(),
            'is_active' => true,
            'is_public' => true,
        ]);
    }

    protected function makeService(array $overrides = []): Service
    {
        $code = 'demo-service-'.Str::lower(Str::random(5));

        $content = $overrides['content'] ?? ['ro' => ['name' => 'Consultație demo'], 'hu' => ['name' => 'Demo vizsgálat']];

        return Service::create($overrides + [
            'code' => $code,
            'content' => $content,
            'published_content' => $content,
            'published_at' => now(),
            'default_duration' => 30,
            'is_active' => true,
            'is_public' => true,
        ]);
    }

    protected function makeOffering(Location $location, Specialist $specialist, Service $service, array $overrides = [], array $linkOverrides = []): Offering
    {
        LocationSpecialist::query()->firstOrCreate(
            ['location_id' => $location->id, 'specialist_id' => $specialist->id],
            $linkOverrides + ['is_active' => true, 'online_enabled' => true, 'min_lead_minutes' => 0, 'max_advance_days' => 90],
        );

        return Offering::create($overrides + [
            'location_id' => $location->id,
            'specialist_id' => $specialist->id,
            'service_id' => $service->id,
            'capacity' => 1,
            'online_enabled' => true,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<int, list<array{0: string, 1: string}>>  $days  ISO weekday => [[start, end], …]
     */
    protected function makeSchedule(Location $location, Specialist $specialist, array $days, array $overrides = [], array $segmentOverrides = [], int $cycleWeek = 1): Schedule
    {
        $schedule = Schedule::create($overrides + [
            'specialist_id' => $specialist->id,
            'location_id' => $location->id,
            'name' => 'Program demo',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'cycle_weeks' => 1,
            'cycle_anchor_date' => '2026-01-05',
        ]);

        $this->addSegments($schedule, $days, $segmentOverrides, $cycleWeek);

        return $schedule;
    }

    protected function addSegments(Schedule $schedule, array $days, array $segmentOverrides = [], int $cycleWeek = 1): void
    {
        foreach ($days as $weekday => $ranges) {
            foreach ($ranges as $i => [$start, $end]) {
                $schedule->segments()->create($segmentOverrides + [
                    'cycle_week' => $cycleWeek,
                    'weekday' => $weekday,
                    'segment' => ['before_noon', 'afternoon', 'evening'][$i] ?? 'extra_1',
                    'start_time' => $start,
                    'end_time' => $end,
                    'slot_minutes' => 15,
                    'online_enabled' => true,
                ]);
            }
        }
    }
}
