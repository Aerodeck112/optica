<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingException;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Booking\Services\IcsBuilder;
use App\Modules\Cms\Seo\Seo;
use App\Modules\Core\Settings\SettingsRepository;
use App\Modules\Finance\Services\Payments\PaymentException;
use App\Modules\Finance\Services\Payments\PaymentGatewayFactory;
use App\Modules\Finance\Services\Payments\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Pagina de gestionare a rezervării, accesibilă prin linkul unic primit pe e-mail.
 * Anularea/reprogramarea online sunt posibile doar dacă regulile au fost configurate și sunt respectate.
 */
class BookingManageController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly SettingsRepository $settings,
        private readonly PaymentService $payments,
        private readonly PaymentGatewayFactory $gateways,
    ) {}

    public function show(string $token)
    {
        $booking = $this->resolve($token);

        return response()->view('site.booking.manage', [
            'booking' => $booking,
            'token' => $token,
            'canCancel' => $this->canCancel($booking),
            'canReschedule' => $this->canReschedule($booking),
            'amountDue' => $this->canPayOnline($booking) ? $this->payments->amountDueOnline($booking) : 0,
            'excludeRef' => BookingApiController::excludeRef($booking),
            'seo' => Seo::make(__('site.booking.manage_title'))->noindex(),
            'showMobileBook' => false,
        ])->header('Referrer-Policy', 'no-referrer');
    }

    public function pay(string $token)
    {
        $booking = $this->resolve($token);
        if (! $this->canPayOnline($booking)) {
            return back()->withErrors(['pay' => __('site.booking.payment_unavailable')]);
        }

        try {
            $payment = $this->payments->startOnline(
                $booking,
                lroute('booking.confirmation', ['code' => $booking->code], $booking->locale).'?t='.$token.'&paid=1',
                lroute('booking.manage', ['token' => $token], $booking->locale),
            );
        } catch (PaymentException $e) {
            return back()->withErrors(['pay' => $e->getMessage()]);
        }

        return redirect()->away($payment->checkout_url);
    }

    /** Plata online e oferită doar dacă procesatorul e configurat și clientul a ales-o sau rezervarea are de achitat avans. */
    private function canPayOnline(Booking $booking): bool
    {
        return $this->gateways->active() !== null
            && in_array($booking->status, ['pending', 'confirmed'], true)
            && ($booking->payment_method === 'online' || ($booking->status === 'pending' && $booking->deposit_minor > 0))
            && $this->payments->amountDueOnline($booking) > 0;
    }

    public function cancel(Request $request, string $token)
    {
        $booking = $this->resolve($token);
        $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        if (! $this->canCancel($booking)) {
            return back()->withErrors(['cancel' => __('site.booking.cancel_not_allowed')]);
        }

        $this->bookings->cancel($booking, $request->input('reason') ?: 'Anulat de client online', 'customer', $booking->customer_id);

        return back()->with('status', __('site.booking.cancelled'));
    }

    public function reschedule(Request $request, string $token)
    {
        $booking = $this->resolve($token);
        $request->validate(['starts_at' => ['required', 'date']]);

        if (! $this->canReschedule($booking)) {
            return back()->withErrors(['reschedule' => __('site.booking.reschedule_not_allowed')]);
        }

        try {
            $this->bookings->reschedule($booking, CarbonImmutable::parse($request->input('starts_at'))->utc(), 'customer', $booking->customer_id, true);
        } catch (BookingException $e) {
            return back()->withErrors(['reschedule' => $e->getMessage()]);
        }

        return back()->with('status', __('site.booking.rescheduled'));
    }

    public function ics(Request $request, string $code, IcsBuilder $ics)
    {
        $booking = Booking::query()->where('code', $code)->first();
        abort_unless($booking && $booking->starts_at && hash_equals((string) $booking->manage_token_hash, hash('sha256', (string) $request->query('t'))), 404);

        return response($ics->forBooking($booking), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="another-world-'.$booking->code.'.ics"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function resolve(string $token): Booking
    {
        abort_if(strlen($token) < 20, 404);
        $booking = $this->bookings->findByManageToken($token);
        abort_unless($booking, 404);

        return $booking->load(['package', 'items']);
    }

    public function canCancel(Booking $booking): bool
    {
        $hours = $this->settings->int('booking.free_cancellation_hours');
        if ($hours === null || ! $booking->canTransitionTo('cancelled')) {
            return false;
        }
        if ($booking->status === 'request' || ! $booking->starts_at) {
            return true;
        }

        return now()->addHours($hours)->lessThanOrEqualTo($booking->starts_at);
    }

    public function canReschedule(Booking $booking): bool
    {
        $hours = $this->settings->int('booking.free_reschedule_hours');
        $max = (int) $this->settings->int('booking.max_reschedules', 1);

        return $hours !== null
            && in_array($booking->status, ['pending', 'confirmed'], true)
            && $booking->starts_at !== null
            && $booking->reschedule_count < $max
            && $booking->package?->supportsInstantBooking()
            && now()->addHours($hours)->lessThanOrEqualTo($booking->starts_at);
    }
}
