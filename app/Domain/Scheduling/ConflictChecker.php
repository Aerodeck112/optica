<?php

namespace App\Domain\Scheduling;

use App\Models\Appointment;
use App\Models\Offering;
use App\Models\Resource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single overlap/capacity/resource/travel rule set used by both the public site and the CRM (PRG-02).
 */
class ConflictChecker
{
    /** @var array<string, int>|null */
    private ?array $travel = null;

    /**
     * Blocking appointments relevant for $offering's specialist (all locations) and its location's resources.
     *
     * @return Collection<int, Appointment>
     */
    public function loadContext(Offering $offering, CarbonImmutable $from, CarbonImmutable $to, ?int $excludeAppointmentId = null): Collection
    {
        $margin = $this->maxTravelMinutes();
        $resourceIds = $this->candidateResources($offering, null)->pluck('id')->all();

        return Appointment::query()
            ->blocking()
            ->overlapping($from->subMinutes($margin), $to->addMinutes($margin))
            ->where(function ($q) use ($offering, $resourceIds) {
                $q->where('specialist_id', $offering->specialist_id);
                if ($resourceIds) {
                    $q->orWhereIn('resource_id', $resourceIds);
                }
            })
            ->when($excludeAppointmentId, fn ($q) => $q->whereKeyNot($excludeAppointmentId))
            ->get(['id', 'location_id', 'specialist_id', 'service_id', 'resource_id', 'block_starts_at', 'block_ends_at', 'status', 'hold_expires_at']);
    }

    /**
     * @param  Interval  $block  appointment interval including buffers
     * @return array{ok: bool, reason: ?string, resource_id: ?int}
     */
    public function check(Offering $offering, Interval $block, Collection $existing, ?int $preferredResourceId = null): array
    {
        $specialistAppointments = $existing->where('specialist_id', $offering->specialist_id);

        // 1. Same specialist in another location: never overlapping, plus travel time.
        foreach ($specialistAppointments->where('location_id', '!=', $offering->location_id) as $other) {
            $before = $this->travelMinutes($other->location_id, $offering->location_id);
            $after = $this->travelMinutes($offering->location_id, $other->location_id);
            $otherStart = CarbonImmutable::instance($other->block_starts_at);
            $otherEnd = CarbonImmutable::instance($other->block_ends_at);
            $clearBefore = $otherEnd->addMinutes($before) <= $block->start;
            $clearAfter = $block->end->addMinutes($after) <= $otherStart;
            if (! $clearBefore && ! $clearAfter) {
                return $this->fail(BookingException::SPECIALIST_OTHER_LOCATION);
            }
        }

        // 2. Same specialist, same location: only the same service may share time, within capacity.
        $sameLocation = $specialistAppointments
            ->where('location_id', $offering->location_id)
            ->filter(fn (Appointment $a) => $this->overlaps($a, $block));

        if ($sameLocation->contains(fn (Appointment $a) => (int) $a->service_id !== (int) $offering->service_id)) {
            return $this->fail(BookingException::SPECIALIST_BUSY);
        }
        if ($sameLocation->isNotEmpty() && $this->maxConcurrent($sameLocation, $block) + 1 > max(1, (int) $offering->capacity)) {
            return $this->fail($offering->capacity > 1 ? BookingException::CAPACITY_REACHED : BookingException::SPECIALIST_BUSY);
        }

        // 3. Resources (cabinet / equipment).
        $resources = $this->candidateResources($offering, $preferredResourceId);
        if ($resources->isEmpty()) {
            return ['ok' => true, 'reason' => null, 'resource_id' => null];
        }
        foreach ($resources as $resource) {
            $busy = $existing
                ->where('resource_id', $resource->id)
                ->filter(fn (Appointment $a) => $this->overlaps($a, $block))
                // Parallel appointments of the same specialist and service share their resource.
                ->reject(fn (Appointment $a) => (int) $a->specialist_id === (int) $offering->specialist_id
                    && (int) $a->service_id === (int) $offering->service_id
                    && (int) $a->location_id === (int) $offering->location_id);
            if ($busy->isEmpty()) {
                return ['ok' => true, 'reason' => null, 'resource_id' => $resource->id];
            }
        }

        return $this->fail(BookingException::RESOURCE_BUSY);
    }

    /** @return Collection<int, resource> */
    public function candidateResources(Offering $offering, ?int $preferredResourceId): Collection
    {
        if ($preferredResourceId) {
            return Resource::query()->whereKey($preferredResourceId)->where('is_active', true)->get();
        }
        if (! $offering->resource_type) {
            return new Collection;
        }

        return Resource::query()
            ->where('location_id', $offering->location_id)
            ->where('type', $offering->resource_type)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    public function travelMinutes(int $fromLocationId, int $toLocationId): int
    {
        if ($fromLocationId === $toLocationId) {
            return 0;
        }
        $this->travel ??= DB::table('location_travel_times')->get()
            ->mapWithKeys(fn ($row) => [$row->from_location_id.'-'.$row->to_location_id => (int) $row->minutes])
            ->all();

        return $this->travel[$fromLocationId.'-'.$toLocationId]
            ?? $this->travel[$toLocationId.'-'.$fromLocationId]
            ?? (int) config('optica.booking.default_travel_minutes');
    }

    public function maxTravelMinutes(): int
    {
        $configured = (int) DB::table('location_travel_times')->max('minutes');

        return max($configured, (int) config('optica.booking.default_travel_minutes'));
    }

    private function overlaps(Appointment $appointment, Interval $block): bool
    {
        return CarbonImmutable::instance($appointment->block_starts_at) < $block->end
            && CarbonImmutable::instance($appointment->block_ends_at) > $block->start;
    }

    /** Peak number of simultaneous appointments inside $block (sweep line; durations may differ). */
    private function maxConcurrent(Collection $appointments, Interval $block): int
    {
        $events = [];
        foreach ($appointments as $a) {
            $start = max(CarbonImmutable::instance($a->block_starts_at)->getTimestamp(), $block->start->getTimestamp());
            $end = min(CarbonImmutable::instance($a->block_ends_at)->getTimestamp(), $block->end->getTimestamp());
            if ($end > $start) {
                $events[] = [$start, 1];
                $events[] = [$end, -1];
            }
        }
        // Ends sort before starts at the same instant: back-to-back is not overlap.
        usort($events, fn ($x, $y) => $x[0] <=> $y[0] ?: $x[1] <=> $y[1]);
        $current = $peak = 0;
        foreach ($events as [, $delta]) {
            $current += $delta;
            $peak = max($peak, $current);
        }

        return $peak;
    }

    private function fail(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'resource_id' => null];
    }
}
