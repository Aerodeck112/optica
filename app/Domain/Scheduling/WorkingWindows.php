<?php

namespace App\Domain\Scheduling;

use App\Models\Offering;
use App\Models\Schedule;
use App\Models\ScheduleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Turns schedules (local wall-clock times, alternating weeks, segments) and exceptions
 * into concrete UTC working windows for one specialist in one location (LOC-02).
 *
 * Precedence on a given date: schedule segments − unavailable exceptions + available exceptions.
 */
class WorkingWindows
{
    private string $timezone;

    public function __construct()
    {
        $this->timezone = config('optica.clinic_timezone');
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * @return array<string, list<Interval>> windows keyed by local date (Y-m-d); interval meta holds
     *                                       slot_minutes, online, resource_id and segment_id
     */
    public function forRange(Offering $offering, CarbonImmutable $fromDate, CarbonImmutable $toDate, bool $onlineOnly = false): array
    {
        return $this->compute($offering->specialist_id, $offering->location_id, $offering->service_id, $fromDate, $toDate, $onlineOnly);
    }

    /**
     * Working time of a specialist in a location regardless of service (calendar background).
     *
     * @return array<string, list<Interval>>
     */
    public function forSpecialist(int $specialistId, int $locationId, CarbonImmutable $fromDate, CarbonImmutable $toDate): array
    {
        return $this->compute($specialistId, $locationId, null, $fromDate, $toDate, false);
    }

    /** @return array<string, list<Interval>> */
    private function compute(int $specialistId, int $locationId, ?int $serviceId, CarbonImmutable $fromDate, CarbonImmutable $toDate, bool $onlineOnly): array
    {
        $from = CarbonImmutable::parse($fromDate->setTimezone($this->timezone)->toDateString(), $this->timezone);
        $to = CarbonImmutable::parse($toDate->setTimezone($this->timezone)->toDateString(), $this->timezone);

        $schedules = Schedule::query()
            ->with('segments')
            ->where('specialist_id', $specialistId)
            ->where('location_id', $locationId)
            ->where('is_active', true)
            ->whereDate('valid_from', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from->toDateString()))
            ->get();

        $exceptions = ScheduleException::query()
            ->where(fn ($q) => $q->whereNull('specialist_id')->orWhere('specialist_id', $specialistId))
            ->where(fn ($q) => $q->whereNull('location_id')->orWhere('location_id', $locationId))
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->whereDate('ends_on', '>=', $from->toDateString())
            ->get();

        $result = [];
        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $result[$day->toDateString()] = $this->windowsForDay($day, $serviceId, $schedules, $exceptions, $onlineOnly);
        }

        return $result;
    }

    /** @return list<Interval> */
    public function forDate(Offering $offering, CarbonImmutable $localDate, bool $onlineOnly = false): array
    {
        return $this->forRange($offering, $localDate, $localDate, $onlineOnly)[$localDate->setTimezone($this->timezone)->toDateString()] ?? [];
    }

    /** @return list<Interval> */
    private function windowsForDay(CarbonImmutable $day, ?int $serviceId, Collection $schedules, Collection $exceptions, bool $onlineOnly): array
    {
        $date = $day->toDateString();
        $windows = [];

        foreach ($schedules as $schedule) {
            if ($schedule->valid_from->toDateString() > $date || ($schedule->valid_to && $schedule->valid_to->toDateString() < $date)) {
                continue;
            }
            $cycleWeek = $this->cycleWeek($schedule, $day);
            foreach ($schedule->segments as $segment) {
                if ((int) $segment->weekday !== $day->dayOfWeekIso || (int) $segment->cycle_week !== $cycleWeek) {
                    continue;
                }
                if (($serviceId !== null && ! $segment->allowsService($serviceId)) || ($onlineOnly && ! $segment->online_enabled)) {
                    continue;
                }
                $windows[] = $this->localInterval($date, $segment->start_time, $segment->end_time, [
                    'slot_minutes' => $segment->slot_minutes,
                    'online' => (bool) $segment->online_enabled,
                    'resource_id' => $segment->resource_id,
                    'segment_id' => $segment->id,
                ]);
            }
        }

        $dayExceptions = $exceptions->filter(fn (ScheduleException $e) => $e->starts_on->toDateString() <= $date && $e->ends_on->toDateString() >= $date);

        $removals = [];
        foreach ($dayExceptions->where('kind', 'unavailable') as $exception) {
            if ($exception->isFullDay()) {
                $windows = [];
                $removals = [];
                break;
            }
            $removals[] = $this->localInterval($date, $exception->start_time, $exception->end_time);
        }
        $windows = Interval::subtractAll($windows, $removals);

        foreach ($dayExceptions->where('kind', 'available') as $exception) {
            // Extra availability must name both the specialist and the location.
            if ($exception->specialist_id === null || $exception->location_id === null || $exception->isFullDay()) {
                continue;
            }
            if ($onlineOnly && ! $exception->online_enabled) {
                continue;
            }
            $windows[] = $this->localInterval($date, $exception->start_time, $exception->end_time, [
                'slot_minutes' => null,
                'online' => (bool) $exception->online_enabled,
                'resource_id' => null,
                'segment_id' => null,
            ]);
        }

        usort($windows, fn (Interval $a, Interval $b) => $a->start <=> $b->start);

        return array_values(array_filter($windows, fn (Interval $w) => ! $w->isEmpty()));
    }

    public function cycleWeek(Schedule $schedule, CarbonImmutable $day): int
    {
        $cycle = max(1, (int) $schedule->cycle_weeks);
        if ($cycle === 1) {
            return 1;
        }
        $anchorMonday = CarbonImmutable::parse($schedule->cycle_anchor_date->toDateString(), $this->timezone)->startOfWeek(CarbonImmutable::MONDAY);
        $dayMonday = CarbonImmutable::parse($day->toDateString(), $this->timezone)->startOfWeek(CarbonImmutable::MONDAY);
        $weeks = intdiv((int) round($anchorMonday->diffInDays($dayMonday, false)), 7);

        return (($weeks % $cycle) + $cycle) % $cycle + 1;
    }

    /** Local wall-clock time on a local date → UTC interval (DST-safe, D-003). */
    public function localInterval(string $date, string $startTime, string $endTime, array $meta = []): Interval
    {
        return new Interval(
            $this->toUtc($date, $startTime),
            $this->toUtc($date, $endTime),
            $meta,
        );
    }

    public function toUtc(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' '.substr($time, 0, 5), $this->timezone)->utc();
    }
}
