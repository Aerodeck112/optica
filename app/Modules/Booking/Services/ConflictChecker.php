<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\BookingResource;
use App\Modules\Booking\Models\Resource;
use App\Modules\Booking\Models\ResourceBlock;
use App\Modules\Booking\Models\SlotHold;
use Carbon\CarbonInterface;

/**
 * Verifică dacă un interval poate fi ocupat pe o resursă.
 * Apelat în interiorul ResourceLocker::run() pentru decizii definitive și în afara lui pentru afișarea sloturilor.
 *
 * Reguli:
 *  - orice blocare internă (mentenanță, eveniment) pe familia resursei → conflict;
 *  - cerere exclusivă (sesiune privată): orice ocupare/hold suprapus pe familie → conflict;
 *  - cerere partajată: ocupările exclusive pe familie → conflict; cele partajate pe aceeași resursă
 *    se adună și nu pot depăși capacitatea.
 */
class ConflictChecker
{
    public function check(
        Resource $resource,
        CarbonInterface $start,
        CarbonInterface $end,
        int $participants,
        bool $exclusive,
        ?int $ignoreBookingId = null,
        ?int $ignoreHoldId = null,
    ): ?string {
        $family = $resource->conflictFamilyIds();

        if ($resource->capacity !== null && $participants > $resource->capacity) {
            return 'capacity_exceeded';
        }

        $blocked = ResourceBlock::query()
            ->whereIn('resource_id', $family)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->exists();
        if ($blocked) {
            return 'blocked';
        }

        $occupancies = BookingResource::query()
            ->whereIn('resource_id', $family)
            ->where('is_active', true)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($ignoreBookingId, fn ($q) => $q->where('booking_id', '!=', $ignoreBookingId))
            ->get(['resource_id', 'participants', 'is_exclusive']);

        $holds = SlotHold::query()
            ->whereIn('resource_id', $family)
            ->whereNull('released_at')
            ->where('expires_at', '>', now())
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($ignoreHoldId, fn ($q) => $q->where('id', '!=', $ignoreHoldId))
            ->get(['resource_id', 'participants', 'is_exclusive']);

        $taken = $occupancies->concat($holds);

        if ($exclusive) {
            return $taken->isNotEmpty() ? 'occupied' : null;
        }

        if ($taken->contains(fn ($row) => $row->is_exclusive || $row->resource_id !== $resource->id)) {
            return 'occupied';
        }

        if ($resource->capacity === null) {
            return 'capacity_unknown';
        }

        $used = (int) $taken->sum('participants');

        return $used + $participants > $resource->capacity ? 'capacity_exceeded' : null;
    }
}
