<?php

namespace App\Domain\Scheduling;

use App\Models\LocationSpecialist;
use App\Models\Offering;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Free slots for an offering, computed with the same rules the booking transaction re-checks (PRG-02).
 */
class AvailabilityService
{
    public function __construct(
        private readonly WorkingWindows $windows,
        private readonly ConflictChecker $conflicts,
    ) {}

    /**
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, resource_id: ?int}>
     */
    public function slots(Offering $offering, CarbonImmutable $fromDate, CarbonImmutable $toDate, bool $online = false, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $offering->loadMissing('service', 'location', 'specialist');

        if (! $offering->is_active || ! $offering->service->is_active) {
            return [];
        }

        $earliest = $now;
        $latest = null;
        if ($online) {
            $link = $this->onlineLink($offering);
            if ($link === null) {
                return [];
            }
            $earliest = $now->addMinutes((int) $link->min_lead_minutes);
            $latest = $now->addDays((int) $link->max_advance_days);
        }

        $windowsByDate = $this->windows->forRange($offering, $fromDate, $toDate, $online);
        $all = array_merge(...array_values($windowsByDate) ?: [[]]);
        if ($all === []) {
            return [];
        }

        $existing = $this->conflicts->loadContext($offering, $all[0]->start->subDay(), end($all)->end->addDay());
        $duration = $offering->durationMinutes();
        $bufferBefore = $offering->bufferBefore();
        $bufferAfter = $offering->bufferAfter();

        $slots = [];
        foreach ($all as $window) {
            $step = max(5, (int) ($window->meta['slot_minutes'] ?? config('optica.booking.default_slot_minutes')));
            for ($start = $window->start; $start->addMinutes($duration) <= $window->end; $start = $start->addMinutes($step)) {
                if ($start < $earliest || ($latest !== null && $start > $latest)) {
                    continue;
                }
                $end = $start->addMinutes($duration);
                $block = new Interval($start->subMinutes($bufferBefore), $end->addMinutes($bufferAfter));
                $result = $this->conflicts->check($offering, $block, $existing, $window->meta['resource_id'] ?? null);
                if ($result['ok']) {
                    $slots[] = ['starts_at' => $start, 'ends_at' => $end, 'resource_id' => $result['resource_id']];
                }
            }
        }

        return $slots;
    }

    /**
     * Earliest free online slot for a service, optionally in one location (public "first available").
     *
     * @return array{offering: Offering, starts_at: CarbonImmutable, ends_at: CarbonImmutable}|null
     */
    public function firstAvailable(int $serviceId, ?int $locationId = null, ?CarbonImmutable $from = null, int $horizonDays = 60): ?array
    {
        $from ??= CarbonImmutable::now();
        $offerings = $this->onlineOfferings($serviceId, $locationId);
        $best = null;

        for ($offset = 0; $offset < $horizonDays && $best === null; $offset += 7) {
            $chunkFrom = $from->addDays($offset);
            $chunkTo = $chunkFrom->addDays(6);
            foreach ($offerings as $offering) {
                $slot = $this->slots($offering, $chunkFrom, $chunkTo, true, $from)[0] ?? null;
                if ($slot && ($best === null || $slot['starts_at'] < $best['starts_at'])) {
                    $best = ['offering' => $offering] + $slot;
                }
            }
        }

        return $best;
    }

    /** @return Collection<int, Offering> */
    public function onlineOfferings(?int $serviceId = null, ?int $locationId = null, ?int $specialistId = null): Collection
    {
        return Offering::query()
            ->with(['service', 'location', 'specialist'])
            ->where('is_active', true)
            ->where('online_enabled', true)
            ->when($serviceId, fn ($q) => $q->where('service_id', $serviceId))
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->when($specialistId, fn ($q) => $q->where('specialist_id', $specialistId))
            ->get()
            ->filter(fn (Offering $o) => $this->onlineLink($o) !== null)
            ->values();
    }

    /** The location–specialist link when every online switch is on, otherwise null (LOC-03). */
    public function onlineLink(Offering $offering): ?LocationSpecialist
    {
        $offering->loadMissing('service', 'location', 'specialist');
        if (! $offering->online_enabled || ! $offering->is_active
            || ! $offering->service->is_active || ! $offering->service->is_public
            || ! $offering->location->is_active || ! $offering->location->is_public || ! $offering->location->online_booking_enabled
            || ! $offering->specialist->is_active || ! $offering->specialist->is_public) {
            return null;
        }

        $link = LocationSpecialist::query()
            ->where('location_id', $offering->location_id)
            ->where('specialist_id', $offering->specialist_id)
            ->first();

        return ($link && $link->is_active && $link->online_enabled && ! $link->online_paused) ? $link : null;
    }

    public function fitsSchedule(Offering $offering, Interval $appointment, bool $onlineOnly): bool
    {
        $localDate = $appointment->start->setTimezone($this->windows->timezone());
        foreach ($this->windows->forDate($offering, $localDate, $onlineOnly) as $window) {
            if ($window->contains($appointment)) {
                return true;
            }
        }

        return false;
    }

    public function windowResource(Offering $offering, Interval $appointment, bool $onlineOnly): ?int
    {
        $localDate = $appointment->start->setTimezone($this->windows->timezone());
        foreach ($this->windows->forDate($offering, $localDate, $onlineOnly) as $window) {
            if ($window->contains($appointment)) {
                return $window->meta['resource_id'] ?? null;
            }
        }

        return null;
    }
}
