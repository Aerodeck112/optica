<?php

namespace App\Domain\Finance;

use App\Models\Order;
use App\Models\Payment;
use App\Models\ServiceCharge;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * Value, payments and balance of an order or a service charge (FIN-01, FIN-02).
 * Value is what the service or order costs; paid is the money received minus refunds. An advance is
 * an ordinary payment marked as such, so paying the balance never counts it twice. Rows are never
 * deleted: a wrong entry is voided with a reason.
 */
class Ledger
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function valueCents(Order|ServiceCharge $payable): int
    {
        return match (true) {
            $payable instanceof Order => $payable->status === 'cancelled' ? 0 : $payable->total_cents,
            default => $payable->voided_at ? 0 : $payable->amount_cents,
        };
    }

    public function paidCents(Order|ServiceCharge $payable): int
    {
        return (int) Payment::valid()
            ->where('payable_type', $payable->getMorphClass())->where('payable_id', $payable->getKey())
            ->selectRaw("coalesce(sum(case when type = 'refund' then -amount_cents else amount_cents end), 0) as paid")
            ->value('paid');
    }

    /** Positive: still owed. Negative: money to give back. */
    public function balanceCents(Order|ServiceCharge $payable): int
    {
        return $this->valueCents($payable) - $this->paidCents($payable);
    }

    /** @return array{value: int, paid: int, balance: int} */
    public function summary(Order|ServiceCharge $payable): array
    {
        $value = $this->valueCents($payable);
        $paid = $this->paidCents($payable);

        return ['value' => $value, 'paid' => $paid, 'balance' => $value - $paid];
    }

    public function recordPayment(Order|ServiceCharge $payable, int $amountCents, string $method, User $user, ?string $reference = null, ?string $note = null, ?string $idempotencyKey = null): Payment
    {
        return $this->record($payable, 'payment', $amountCents, $method, $user, $reference, $note, $idempotencyKey);
    }

    public function recordRefund(Order|ServiceCharge $payable, int $amountCents, string $method, User $user, string $note, ?string $idempotencyKey = null): Payment
    {
        if (mb_strlen(trim($note)) < 3) {
            throw new FinanceException(__('finance.errors.reason_required'));
        }

        return $this->record($payable, 'refund', $amountCents, $method, $user, null, $note, $idempotencyKey);
    }

    private function record(Order|ServiceCharge $payable, string $type, int $amountCents, string $method, User $user, ?string $reference, ?string $note, ?string $idempotencyKey): Payment
    {
        if ($amountCents <= 0) {
            throw new FinanceException(__('finance.errors.amount_positive'));
        }
        if (! in_array($method, Payment::METHODS, true)) {
            throw new FinanceException(__('finance.errors.method'));
        }
        if ($idempotencyKey && ($existing = Payment::where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }

        return DB::transaction(function () use ($payable, $type, $amountCents, $method, $user, $reference, $note, $idempotencyKey) {
            // Serialises every movement on the same order or charge, so two cashiers cannot overpay it.
            $locked = $payable::whereKey($payable->getKey())->lockForUpdate()->firstOrFail();
            $summary = $this->summary($locked);
            if ($type === 'payment') {
                if ($locked instanceof ServiceCharge && $locked->voided_at) {
                    throw new FinanceException(__('finance.errors.voided_charge'));
                }
                if ($locked instanceof Order && $locked->status === 'cancelled') {
                    throw new FinanceException(__('finance.errors.cancelled_order'));
                }
                if ($amountCents > $summary['balance']) {
                    throw new FinanceException(__('finance.errors.over_balance', ['balance' => \App\Support\Money::format(max(0, $summary['balance']))]));
                }
            } elseif ($amountCents > $summary['paid']) {
                throw new FinanceException(__('finance.errors.over_paid', ['paid' => \App\Support\Money::format($summary['paid'])]));
            }

            $payment = Payment::create([
                'patient_id' => $locked->patient_id,
                'location_id' => $locked->location_id,
                'payable_type' => $locked->getMorphClass(),
                'payable_id' => $locked->getKey(),
                'type' => $type,
                // Money received before handover is an advance (CMD-01); it is never counted again.
                'is_advance' => $type === 'payment' && $locked instanceof Order && $locked->status !== 'delivered' && $amountCents < $summary['balance'],
                'amount_cents' => $amountCents,
                'method' => $method,
                'reference' => $reference ? mb_substr(trim($reference), 0, 120) : null,
                'note' => $note ? mb_substr(trim($note), 0, 300) : null,
                'received_at' => now(),
                'operator_id' => $user->id,
                'idempotency_key' => $idempotencyKey,
            ]);
            $this->audit->log('payments.'.$type, $payment, ['amount_cents' => $amountCents, 'payable' => $payment->payable_type.':'.$payment->payable_id], $user, $payment->location_id);

            return $payment;
        });
    }

    public function void(Payment $payment, string $reason, User $user): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new FinanceException(__('finance.errors.reason_required'));
        }
        DB::transaction(function () use ($payment, $reason, $user) {
            $class = Relation::getMorphedModel($payment->payable_type) ?? abort(404);
            $payable = $class::whereKey($payment->payable_id)->lockForUpdate()->firstOrFail();
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->voided_at) {
                throw new FinanceException(__('finance.errors.already_voided'));
            }
            // Voiding a payment must not leave more refunded than received.
            if ($locked->type === 'payment' && $this->paidCents($payable) - $locked->amount_cents < 0) {
                throw new FinanceException(__('finance.errors.void_refund_first'));
            }
            $locked->forceFill(['voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => mb_substr(trim($reason), 0, 300)])->save();
            $this->audit->log('payments.voided', $locked, ['amount_cents' => $locked->amount_cents, 'type' => $locked->type], $user, $locked->location_id);
        });
    }

    /** Charges and orders of a patient with their balances. */
    public function patientBalance(int $patientId): array
    {
        $rows = [];
        foreach (Order::where('patient_id', $patientId)->get() as $order) {
            $rows[] = ['payable' => $order] + $this->summary($order);
        }
        foreach (ServiceCharge::where('patient_id', $patientId)->get() as $charge) {
            $rows[] = ['payable' => $charge] + $this->summary($charge);
        }

        return $rows;
    }

    public static function payableFor(string $type, int $id): Model
    {
        return match ($type) {
            'order' => Order::findOrFail($id),
            'service_charge' => ServiceCharge::findOrFail($id),
            default => abort(404),
        };
    }
}
