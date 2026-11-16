<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\BookingResource;
use App\Modules\Booking\Models\Resource;
use App\Modules\Booking\Models\ResourceBlock;
use App\Modules\Catalog\Models\Package;
use App\Modules\Core\Settings\SettingsRepository;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Sloturile reale pentru o zi: program × durată × ocupare. Folosit de site, asistentul AI și recepție.
 * Rezultatul este informativ; decizia finală se ia sub blocare în BookingService.
 */
class AvailabilityService
{
    public function __construct(
        private readonly ScheduleService $schedule,
        private readonly ConflictChecker $conflicts,
        private readonly SettingsRepository $settings,
        private readonly VenueOpening $opening,
    ) {}

    /**
     * @return array{status: string, slots: array<int, array{starts_at: string, local_time: string, available: bool, resource_id: ?int, reason: ?string}>, message: ?string}
     *                                                                                                                                                                       status: ok | closed | not_schedulable | out_of_range
     */
    public function slotsFor(Package $package, string $localDate, int $participants, int $extraMinutes = 0, ?int $ignoreBookingId = null, bool $staff = false): array
    {
        if (! $package->hasScheduling()) {
            return ['status' => 'not_schedulable', 'slots' => [], 'message' => 'Pachetul nu are încă durata, capacitatea sau resursele configurate.'];
        }

        $today = LocalTime::now()->toDateString();
        $maxDays = (int) $this->settings->int('booking.max_advance_days', 90);
        if (! $staff && ($localDate < $today || $localDate > LocalTime::now()->addDays($maxDays)->toDateString())) {
            return ['status' => 'out_of_range', 'slots' => [], 'message' => null];
        }
        if (! $staff && $localDate < $this->opening->firstBookableDate()) {
            return ['status' => 'out_of_range', 'slots' => [], 'message' => 'Rezervările online încep din ziua deschiderii: '.$this->opening->formatted().'.'];
        }

        $window = $this->schedule->windowFor($localDate);
        if (! $window) {
            return ['status' => 'closed', 'slots' => [], 'message' => null];
        }

        $resources = $this->eligibleResources($package, $staff);
        if ($resources === []) {
            return ['status' => 'not_schedulable', 'slots' => [], 'message' => 'Nu există resurse active pentru acest pachet.'];
        }

        $interval = max(5, (int) $this->settings->int('booking.slot_interval_minutes', 30));
        $leadMinutes = (int) ($package->min_lead_minutes ?? $this->settings->int('booking.min_lead_minutes', 120));
        $earliest = $staff ? CarbonImmutable::now()->subMinutes(30) : CarbonImmutable::now()->addMinutes($leadMinutes);
        $experienceMinutes = (int) $package->slot_minutes + $extraMinutes;
        $exclusive = $package->session_mode !== 'shared';

        $bufferAfter = $package->bufferAfterMinutes();
        $slots = [];
        // Iterăm în UTC între deschidere și închidere: în zilele cu schimbarea orei, etichetele locale rămân corecte.
        foreach ($this->candidateStarts($window, $resources, $experienceMinutes, $interval, $ignoreBookingId) as $start) {
            $occupiedStart = $start->subMinutes((int) $package->buffer_before_minutes);
            $occupiedEnd = $start->addMinutes($experienceMinutes + $bufferAfter);

            $reason = null;
            $resourceId = null;
            if ($start->lessThan($earliest)) {
                $reason = 'too_soon';
            } else {
                foreach ($resources as $resource) {
                    $conflict = $this->conflicts->check($resource, $occupiedStart, $occupiedEnd, $participants, $exclusive, $ignoreBookingId);
                    if ($conflict === null) {
                        $resourceId = $resource->id;
                        $reason = null;
                        break;
                    }
                    $reason = $conflict;
                }
            }

            $slots[] = [
                'starts_at' => $start->toIso8601ZuluString(),
                'local_time' => LocalTime::toLocal($start)->format('H:i'),
                'available' => $reason === null,
                'resource_id' => $resourceId,
                'reason' => $reason,
            ];
        }

        return ['status' => 'ok', 'slots' => $slots, 'message' => $window['note']];
    }

    /**
     * Orele de început: grila din setări, plus momentul imediat de după fiecare rezervare existentă (sfârșitul pauzei
     * de pregătire). Astfel runda următoare poate începe exact după pauză (ex. 10:00 → 11:20), fără minute pierdute.
     *
     * @param  array{open: CarbonImmutable, close: CarbonImmutable}  $window
     * @param  array<int, resource>  $resources
     * @return array<int, CarbonImmutable>
     */
    private function candidateStarts(array $window, array $resources, int $experienceMinutes, int $interval, ?int $ignoreBookingId): array
    {
        $fits = fn (CarbonImmutable $start) => $start->greaterThanOrEqualTo($window['open']) && $start->addMinutes($experienceMinutes)->lessThanOrEqualTo($window['close']);
        $starts = [];
        for ($start = $window['open']; $fits($start); $start = $start->addMinutes($interval)) {
            $starts[$start->getTimestamp()] = $start;
        }
        $family = array_values(array_unique(array_merge(...array_map(fn (Resource $r) => $r->conflictFamilyIds(), $resources))));
        $ends = BookingResource::query()->whereIn('resource_id', $family)->where('is_active', true)
            ->where('ends_at', '>', $window['open'])->where('ends_at', '<', $window['close'])
            ->when($ignoreBookingId, fn ($q) => $q->where('booking_id', '!=', $ignoreBookingId))
            ->pluck('ends_at')
            ->merge(ResourceBlock::query()->whereIn('resource_id', $family)->where('ends_at', '>', $window['open'])->where('ends_at', '<', $window['close'])->pluck('ends_at'));
        foreach ($ends as $end) {
            $start = CarbonImmutable::parse($end)->utc()->second(0);
            if ($fits($start)) {
                $starts[$start->getTimestamp()] = $start;
            }
        }
        ksort($starts);

        return array_values($starts);
    }

    /** Verifică un singur moment de început (folosit de AI și de formularele fără JS). */
    public function isAvailable(Package $package, CarbonImmutable $startsAt, int $participants, int $extraMinutes = 0, ?int $ignoreBookingId = null, bool $staff = false): ?int
    {
        $localDate = LocalTime::localDate($startsAt);
        $result = $this->slotsFor($package, $localDate, $participants, $extraMinutes, $ignoreBookingId, $staff);
        foreach ($result['slots'] as $slot) {
            if ($slot['available'] && CarbonImmutable::parse($slot['starts_at'])->equalTo($startsAt)) {
                return $slot['resource_id'];
            }
        }

        return null;
    }

    /** @return array<int, resource> */
    public function eligibleResources(Package $package, bool $staff = false): array
    {
        $ids = array_map('intval', (array) $package->resource_ids);
        if (! $ids) {
            return [];
        }
        $resources = Resource::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->when(! $staff, fn ($q) => $q->where('is_public', true))
            ->get()
            ->keyBy('id');

        // păstrează ordinea de preferință din pachet
        return array_values(array_filter(array_map(fn ($id) => $resources[$id] ?? null, $ids)));
    }
}
