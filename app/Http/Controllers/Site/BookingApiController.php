<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\AvailabilityService;
use App\Modules\Booking\Services\BookingException;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Booking\Services\PricingService;
use App\Modules\Catalog\Models\Package;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * API public, fără date personale. Disponibilitatea și prețurile provin din aceleași servicii folosite de recepție și AI.
 */
class BookingApiController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly PricingService $pricing,
        private readonly BookingService $bookings,
    ) {}

    public function slots(Request $request): JsonResponse
    {
        $data = $request->validate([
            'package' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'participants' => ['required', 'integer', 'min:1', 'max:200'],
            'addons' => ['nullable', 'array'],
            'addons.*' => ['integer', 'min:0', 'max:20'],
            'exclude' => ['nullable', 'string', 'max:500'],
        ]);

        $package = Package::query()->active()->findOrFail($data['package']);
        $ignore = null;
        if (! empty($data['exclude'])) {
            try {
                $ignore = (int) Crypt::decryptString($data['exclude']);
            } catch (\Throwable) {
                abort(422);
            }
        }

        $extra = $this->pricing->quote($package, null, (int) $data['participants'], array_map('intval', $data['addons'] ?? []))->extraMinutes;
        $result = $this->availability->slotsFor($package, $data['date'], (int) $data['participants'], $extra, $ignore);

        // motivul indisponibilității nu se expune public (nu dezvăluim ocuparea în detaliu)
        $result['slots'] = array_map(fn ($s) => ['starts_at' => $s['starts_at'], 'local_time' => $s['local_time'], 'available' => $s['available']], $result['slots']);

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'package_id' => ['required', 'integer'],
            'participants' => ['nullable', 'integer', 'min:1', 'max:200'],
            'starts_at' => ['nullable', 'date'],
            'addons' => ['nullable', 'array'],
            'addons.*' => ['integer', 'min:0', 'max:20'],
        ]);

        $package = Package::query()->active()->findOrFail($data['package_id']);
        $participants = (int) ($data['participants'] ?? ($package->min_participants ?: 1));
        $startsAt = ! empty($data['starts_at']) ? CarbonImmutable::parse($data['starts_at'])->utc() : null;
        $quote = $this->pricing->quote($package, $startsAt, $participants, array_map('intval', $data['addons'] ?? []));
        $locale = app()->getLocale();

        $lines = array_map(fn ($item) => $item['description'].' × '.rtrim(rtrim(number_format((float) $item['quantity'], 2, ',', ''), '0'), ',').' = '.Money::format($item['total_minor']), $quote->items);

        return response()->json([
            'package' => $package->getTranslation('name', $locale),
            'when' => $startsAt ? LocalTime::format($startsAt, 'l, j F Y, H:i') : null,
            'slot_minutes' => $package->slot_minutes ? ($package->slot_minutes + $quote->extraMinutes).' min' : null,
            'play_minutes' => $package->play_minutes ? $package->play_minutes.' min' : null,
            'lines' => $quote->priced ? $lines : [],
            'total' => $quote->priced ? Money::format($quote->totalMinor) : null,
            'deposit' => $quote->priced && $quote->depositMinor ? Money::format($quote->depositMinor) : null,
            'note' => $package->supportsInstantBooking() ? null : __('site.booking.request_mode'),
        ]);
    }

    public function hold(Request $request): JsonResponse
    {
        $data = $request->validate([
            'package_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'participants' => ['required', 'integer', 'min:1', 'max:200'],
            'addons' => ['nullable', 'array'],
            'addons.*' => ['integer', 'min:0', 'max:20'],
        ]);

        $package = Package::query()->active()->findOrFail($data['package_id']);

        try {
            $result = $this->bookings->hold($package, CarbonImmutable::parse($data['starts_at'])->utc(), (int) $data['participants'], array_map('intval', $data['addons'] ?? []));
        } catch (BookingException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 409);
        }

        return response()->json([
            'hold_id' => $result['hold']->public_id,
            'token' => $result['token'],
            'expires_at' => $result['hold']->expires_at->toIso8601String(),
        ]);
    }

    public function release(Request $request): JsonResponse
    {
        $payload = $request->json()->all() ?: $request->all();
        if (! empty($payload['hold_id']) && ! empty($payload['token']) && is_string($payload['hold_id']) && is_string($payload['token'])) {
            $this->bookings->releaseHold($payload['hold_id'], $payload['token']);
        }

        return response()->json(['ok' => true]);
    }

    /** Referință opacă pentru excluderea propriei rezervări la reprogramare. */
    public static function excludeRef(Booking $booking): string
    {
        return Crypt::encryptString((string) $booking->id);
    }
}
