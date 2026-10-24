<?php

namespace App\Modules\Finance\Services\Payments;

use App\Models\User;
use App\Modules\Audit\AuditLogger;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Finance\Models\Payment;
use App\Modules\Loyalty\Services\LoyaltyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Încasări: manuale la recepție (numerar, card POS, transfer) și online prin procesator.
 * Totalurile rezervării (achitat, rambursat, status plată) se recalculează mereu din plăți, sub blocare.
 */
class PaymentService
{
    public const MANUAL_METHODS = ['cash', 'card_pos', 'bank_transfer'];

    public function __construct(
        private readonly PaymentGatewayFactory $gateways,
        private readonly BookingService $bookings,
        private readonly LoyaltyService $loyalty,
        private readonly AuditLogger $audit,
    ) {}

    public function recordManual(Booking $booking, string $method, int $amountMinor, User $user, ?string $note = null, ?string $idempotencyKey = null): Payment
    {
        if (! in_array($method, self::MANUAL_METHODS, true)) {
            throw new PaymentException('method', 'Metoda de plată nu poate fi înregistrată manual.');
        }
        if ($amountMinor <= 0) {
            throw new PaymentException('amount', 'Suma trebuie să fie pozitivă.');
        }

        $payment = DB::transaction(function () use ($booking, $method, $amountMinor, $user, $note, $idempotencyKey) {
            if ($idempotencyKey && ($existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first())) {
                return $existing;
            }
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['cancelled', 'expired'], true)) {
                throw new PaymentException('booking_closed', 'Rezervarea este anulată sau expirată; nu se mai pot înregistra încasări.');
            }
            $balance = $locked->balanceMinor();
            if ($balance !== null && $amountMinor > $balance) {
                throw new PaymentException('amount', 'Suma depășește restul de plată ('.money($balance).').');
            }

            $payment = Payment::query()->create([
                'uuid' => (string) Str::uuid(),
                'booking_id' => $locked->id,
                'customer_id' => $locked->customer_id,
                'method' => $method,
                'purpose' => $this->purposeFor($locked, $amountMinor),
                'amount_minor' => $amountMinor,
                'currency' => $locked->currency,
                'status' => 'succeeded',
                'succeeded_at' => now(),
                'recorded_by' => $user->id,
                'note' => $note ? mb_substr($note, 0, 255) : null,
                'idempotency_key' => $idempotencyKey,
            ]);
            $this->bookings->recordEvent($locked, 'payment_recorded', null, null, 'user', $user->id, ['payment_id' => $payment->id, 'method' => $method, 'amount_minor' => $amountMinor]);
            $this->audit->log('payment.recorded', $payment, ['method' => $method, 'amount_minor' => $amountMinor]);

            return $payment;
        });

        $this->afterPaymentChange($booking->fresh());

        return $payment;
    }

    /** Pornește (sau reia) plata online a avansului / restului. Întoarce plata cu linkul de checkout. */
    public function startOnline(Booking $booking, string $successUrl, string $cancelUrl): Payment
    {
        $gateway = $this->gateways->active() ?? throw new PaymentException('unavailable', 'Plata online nu este disponibilă momentan.');
        $amount = $this->amountDueOnline($booking);
        if ($amount <= 0) {
            throw new PaymentException('nothing_due', 'Nu există o sumă de achitat online pentru această rezervare.');
        }

        $existing = $booking->payments()->where('method', 'online')->where('status', 'pending')->where('amount_minor', $amount)
            ->whereNotNull('checkout_url')->where('expires_at', '>', now()->addMinutes(5))->latest('id')->first();
        if ($existing) {
            return $existing;
        }

        $payment = Payment::query()->create([
            'uuid' => (string) Str::uuid(),
            'booking_id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'method' => 'online',
            'purpose' => $this->purposeFor($booking, $amount),
            'amount_minor' => $amount,
            'currency' => $booking->currency,
            'status' => 'pending',
            'provider' => $gateway->name(),
        ]);

        try {
            $session = $gateway->createCheckout($payment, 'Another World Cluj — rezervarea '.$booking->code, $successUrl, $cancelUrl);
        } catch (\Throwable $e) {
            $payment->forceFill(['status' => 'failed', 'note' => 'Sesiunea de plată nu a putut fi creată'])->save();
            report($e);

            throw new PaymentException('provider', 'Plata online nu a putut fi pornită. Încearcă din nou sau alege altă metodă.');
        }
        $payment->forceFill(['checkout_url' => $session['url'], 'provider_reference' => $session['reference'], 'expires_at' => $session['expires_at']])->save();

        return $payment;
    }

    /** Suma cerută online: avansul dacă nu a fost achitat, altfel restul de plată. */
    public function amountDueOnline(Booking $booking): int
    {
        if (! in_array($booking->status, ['pending', 'confirmed'], true)) {
            return 0;
        }
        $balance = $booking->balanceMinor();
        $paid = (int) $booking->paid_minor - (int) $booking->refunded_minor;
        $deposit = (int) $booking->deposit_minor;
        if ($deposit > 0 && $paid < $deposit) {
            return $balance === null ? $deposit - $paid : min($deposit - $paid, $balance);
        }

        return (int) ($balance ?? 0);
    }

    /**
     * Confirmarea venită de la procesator. Idempotentă: o plată deja reușită nu se mai aplică a doua oară.
     * Suma și moneda trebuie să corespundă exact plății create de aplicație.
     */
    public function markSucceeded(Payment $payment, ?int $amountMinor, ?string $currency, array $providerData = []): bool
    {
        $applied = DB::transaction(function () use ($payment, $amountMinor, $currency, $providerData) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['succeeded', 'refunded', 'partially_refunded'], true)) {
                return false;
            }
            if ($amountMinor !== $locked->amount_minor || ($currency !== null && strtoupper($currency) !== $locked->currency)) {
                throw new PaymentException('mismatch', "Suma/moneda confirmată ({$amountMinor} {$currency}) diferă de plata {$locked->uuid}.");
            }
            $locked->forceFill([
                'status' => 'succeeded',
                'succeeded_at' => now(),
                'provider_data' => array_merge((array) $locked->provider_data, $providerData),
            ])->save();
            if ($locked->booking_id) {
                $this->bookings->recordEvent($locked->booking, 'payment_recorded', null, null, 'system', null, ['payment_id' => $locked->id, 'method' => 'online', 'amount_minor' => $locked->amount_minor]);
            }

            return true;
        });

        if ($applied && $payment->booking_id) {
            $this->afterPaymentChange($payment->booking()->first());
        }

        return $applied;
    }

    public function markClosed(Payment $payment, string $status): void
    {
        Payment::query()->whereKey($payment->id)->where('status', 'pending')->update(['status' => $status, 'checkout_url' => null, 'updated_at' => now()]);
    }

    /** Recalculează sumele rezervării din plăți și aplică efectele (confirmare, puncte de fidelitate). */
    public function afterPaymentChange(?Booking $booking): void
    {
        if (! $booking) {
            return;
        }
        $booking = $this->recalculate($booking);

        $paidNet = $booking->paid_minor - $booking->refunded_minor;
        if ($booking->status === 'pending' && $paidNet > 0 && $paidNet >= (int) $booking->deposit_minor) {
            $this->bookings->confirm($booking);
        } elseif (in_array($booking->status, ['expired', 'cancelled'], true) && $paidNet > 0 && $booking->wasChanged('paid_minor')) {
            // plată sosită după expirare/anulare: echipa decide reprogramarea sau rambursarea
            $this->bookings->recordEvent($booking, 'payment_after_close', null, null, 'system', null, ['paid_minor' => $booking->paid_minor]);
        }
        if ($booking->status === 'completed') {
            $this->loyalty->awardForBooking($booking->fresh(['customer.tier']));
        }
    }

    public function recalculate(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking) {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $sums = Payment::query()->where('booking_id', $locked->id)->whereIn('status', ['succeeded', 'refunded', 'partially_refunded'])
                ->selectRaw('COALESCE(SUM(amount_minor), 0) AS paid, COALESCE(SUM(refunded_minor), 0) AS refunded')->first();
            $paid = (int) $sums->paid;
            $refunded = (int) $sums->refunded;
            $locked->forceFill(['paid_minor' => $paid, 'refunded_minor' => $refunded, 'payment_status' => $this->statusFor($locked, $paid, $refunded)])->save();

            return $locked;
        });
    }

    public function statusFor(Booking $booking, int $paid, int $refunded): string
    {
        $net = $paid - $refunded;
        if ($refunded > 0) {
            return $net <= 0 ? 'refunded' : 'partially_refunded';
        }
        if ($paid <= 0) {
            return 'unpaid';
        }
        $due = $booking->total_minor === null ? null : $booking->total_minor - (int) $booking->discount_minor;
        if ($due !== null && $net >= $due) {
            return 'paid';
        }

        return (int) $booking->deposit_minor > 0 && $net >= (int) $booking->deposit_minor ? 'deposit_paid' : 'partially_paid';
    }

    private function purposeFor(Booking $booking, int $amount): string
    {
        $paidNet = (int) $booking->paid_minor - (int) $booking->refunded_minor;
        if ($paidNet > 0) {
            return 'balance';
        }

        return (int) $booking->deposit_minor > 0 && $amount <= (int) $booking->deposit_minor && $amount < (int) ($booking->balanceMinor() ?? PHP_INT_MAX) ? 'deposit' : 'booking';
    }
}
