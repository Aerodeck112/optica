<?php

namespace App\Modules\Finance\Services\Payments;

use App\Models\User;
use App\Modules\Audit\AuditLogger;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Refund;
use App\Modules\Loyalty\Services\LoyaltyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rambursări în doi pași: cerere (recepție/manager) și aprobare (permisiunea finance.refund).
 * Plățile online se rambursează prin procesator cu cheie de idempotență; cele manuale se marchează ca returnate.
 */
class RefundService
{
    public function __construct(
        private readonly PaymentGatewayFactory $gateways,
        private readonly PaymentService $payments,
        private readonly BookingService $bookings,
        private readonly LoyaltyService $loyalty,
        private readonly AuditLogger $audit,
    ) {}

    /** Suma care mai poate fi cerută: net încasat minus cererile încă deschise. */
    public function refundable(Payment $payment): int
    {
        if (! in_array($payment->status, ['succeeded', 'partially_refunded'], true)) {
            return 0;
        }
        $open = (int) $payment->refunds()->whereIn('status', ['requested', 'approved', 'processing'])->sum('amount_minor');

        return max(0, $payment->netMinor() - $open);
    }

    public function request(Payment $payment, int $amountMinor, string $reason, User $user): Refund
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new PaymentException('reason', 'Motivul rambursării este obligatoriu.');
        }

        return DB::transaction(function () use ($payment, $amountMinor, $reason, $user) {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($amountMinor <= 0 || $amountMinor > $this->refundable($locked)) {
                throw new PaymentException('amount', 'Suma depășește cât se mai poate rambursa ('.money($this->refundable($locked)).').');
            }
            $refund = Refund::query()->create([
                'payment_id' => $locked->id,
                'amount_minor' => $amountMinor,
                'method' => $locked->method === 'online' ? 'provider' : 'manual',
                'status' => 'requested',
                'reason' => mb_substr($reason, 0, 255),
                'idempotency_key' => 'refund-'.Str::uuid(),
                'requested_by' => $user->id,
            ]);
            $this->audit->log('refund.requested', $refund, ['payment_id' => $locked->id, 'amount_minor' => $amountMinor]);
            if ($locked->booking) {
                $this->bookings->recordEvent($locked->booking, 'refund_requested', null, null, 'user', $user->id, ['refund_id' => $refund->id, 'amount_minor' => $amountMinor]);
            }

            return $refund;
        });
    }

    public function reject(Refund $refund, User $user, string $reason): Refund
    {
        $this->assertCanApprove($user);
        $updated = Refund::query()->whereKey($refund->id)->where('status', 'requested')
            ->update(['status' => 'rejected', 'approved_by' => $user->id, 'rejection_reason' => mb_substr($reason, 0, 255), 'processed_at' => now(), 'updated_at' => now()]);
        if (! $updated) {
            throw new PaymentException('state', 'Cererea nu mai este în așteptare.');
        }
        $this->audit->log('refund.rejected', $refund, ['reason' => $reason]);

        return $refund->fresh();
    }

    /**
     * Aprobă și execută. Pentru procesator, un răspuns neclar lasă rambursarea „în procesare”; reîncercarea
     * folosește aceeași cheie de idempotență, deci procesatorul nu returnează banii de două ori.
     */
    public function approve(Refund $refund, User $user): Refund
    {
        $this->assertCanApprove($user);

        $claimed = Refund::query()->whereKey($refund->id)->whereIn('status', ['requested', 'processing', 'failed'])
            ->update(['status' => 'processing', 'approved_by' => $user->id, 'updated_at' => now()]);
        if (! $claimed) {
            throw new PaymentException('state', 'Rambursarea a fost deja procesată sau respinsă.');
        }
        $refund = $refund->fresh(['payment']);

        if ($refund->method === 'manual') {
            return $this->complete($refund, null, $user);
        }

        $gateway = $this->gateways->byName((string) $refund->payment->provider)
            ?? throw new PaymentException('unavailable', 'Procesatorul de plăți nu este configurat.');
        try {
            $result = $gateway->refund($refund->payment, $refund->amount_minor, $refund->idempotency_key);
        } catch (\Throwable $e) {
            report($e);
            $refund->forceFill(['error' => 'Răspuns neclar de la procesator; verificați în contul procesatorului înainte de reîncercare.'])->save();

            return $refund;
        }
        if (! $result['ok']) {
            $refund->forceFill(['status' => 'failed', 'error' => mb_substr((string) $result['error'], 0, 1000)])->save();
            $this->audit->log('refund.failed', $refund, ['error' => $result['error']]);

            return $refund;
        }

        return $this->complete($refund, $result['reference'], $user);
    }

    private function complete(Refund $refund, ?string $reference, User $user): Refund
    {
        DB::transaction(function () use ($refund, $reference, $user) {
            $payment = Payment::query()->whereKey($refund->payment_id)->lockForUpdate()->firstOrFail();
            $refunded = min($payment->amount_minor, $payment->refunded_minor + $refund->amount_minor);
            $payment->forceFill(['refunded_minor' => $refunded, 'status' => $refunded >= $payment->amount_minor ? 'refunded' : 'partially_refunded'])->save();
            $refund->forceFill(['status' => 'succeeded', 'provider_reference' => $reference, 'error' => null, 'processed_at' => now()])->save();
            $this->audit->log('refund.succeeded', $refund, ['amount_minor' => $refund->amount_minor, 'approved_by' => $user->id]);
            if ($payment->booking) {
                $this->bookings->recordEvent($payment->booking, 'refund_succeeded', null, null, 'user', $user->id, ['refund_id' => $refund->id, 'amount_minor' => $refund->amount_minor]);
            }
        });

        $booking = $refund->payment->booking()->first();
        if ($booking) {
            $booking = $this->payments->recalculate($booking);
            if ($booking->payment_status === 'refunded') {
                $this->loyalty->reverseForBooking($booking, $user->id);
            }
        }

        return $refund->fresh();
    }

    private function assertCanApprove(User $user): void
    {
        if (! $user->can('finance.refund')) {
            throw new PaymentException('forbidden', 'Aprobarea rambursărilor necesită permisiunea „Aprobă rambursări”.');
        }
    }
}
