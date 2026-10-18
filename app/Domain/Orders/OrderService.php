<?php

namespace App\Domain\Orders;

use App\Domain\Finance\FinanceException;
use App\Domain\Finance\Ledger;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\Patient;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Glasses orders (CMD-01…03): items, estimated date, status with author and time for every change.
 */
class OrderService
{
    public function __construct(private readonly AuditLogger $audit, private readonly Ledger $ledger) {}

    /**
     * @param  array{location_id: int, responsible_id?: ?int, prescription_id?: ?int, appointment_id?: ?int, due_on?: ?string, notes?: ?string}  $data
     * @param  list<array{kind: string, description: string, quantity: int, unit_price_cents: int}>  $items
     */
    public function create(Patient $patient, array $data, array $items, User $user): Order
    {
        return DB::transaction(function () use ($patient, $data, $items, $user) {
            $order = Order::create([
                'number' => 'tmp-'.bin2hex(random_bytes(6)),
                'patient_id' => $patient->id,
                'location_id' => $data['location_id'],
                'responsible_id' => $data['responsible_id'] ?? $user->id,
                'prescription_id' => $data['prescription_id'] ?? null,
                'appointment_id' => $data['appointment_id'] ?? null,
                'due_on' => $data['due_on'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);
            // Readable number per year, stable once issued: C2026-000123.
            $order->number = 'C'.now(config('optica.clinic_timezone'))->format('Y').'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT);
            $this->replaceItems($order, $items);
            $order->save();
            OrderEvent::create(['order_id' => $order->id, 'user_id' => $user->id, 'type' => 'created', 'to_status' => 'new', 'created_at' => now()]);
            $this->audit->log('orders.created', $order, ['number' => $order->number], $user, $order->location_id);

            return $order;
        });
    }

    public function update(Order $order, array $data, array $items, int $lockVersion, User $user): Order
    {
        return DB::transaction(function () use ($order, $data, $items, $lockVersion, $user) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->lock_version !== $lockVersion) {
                throw new FinanceException(__('orders.errors.conflict'));
            }
            if (! $locked->isOpen()) {
                throw new FinanceException(__('orders.errors.closed'));
            }
            $locked->fill(array_intersect_key($data, array_flip(['location_id', 'responsible_id', 'prescription_id', 'appointment_id', 'due_on', 'notes'])));
            $this->replaceItems($locked, $items);
            // The value can never drop below the money already received.
            if ($locked->total_cents < $this->ledger->paidCents($locked)) {
                throw new FinanceException(__('orders.errors.total_below_paid'));
            }
            $locked->lock_version++;
            $locked->save();
            OrderEvent::create(['order_id' => $locked->id, 'user_id' => $user->id, 'type' => 'edited', 'created_at' => now()]);
            $this->audit->log('orders.updated', $locked, [], $user, $locked->location_id);

            return $locked;
        });
    }

    public function changeStatus(Order $order, string $status, User $user, ?string $note = null, bool $allowUnpaid = false): Order
    {
        return DB::transaction(function () use ($order, $status, $user, $note, $allowUnpaid) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $locked->canMoveTo($status)) {
                throw new FinanceException(__('orders.errors.transition', ['from' => __('orders.statuses.'.$locked->status), 'to' => __('orders.statuses.'.$status)]));
            }
            if ($status === 'cancelled' && mb_strlen(trim((string) $note)) < 3) {
                throw new FinanceException(__('finance.errors.reason_required'));
            }
            $balance = $this->ledger->balanceCents($locked);
            if ($status === 'delivered' && $balance > 0 && ! $allowUnpaid) {
                throw new FinanceException(__('orders.errors.unpaid', ['balance' => \App\Support\Money::format($balance)]));
            }
            $from = $locked->status;
            $locked->status = $status;
            match ($status) {
                'ready' => $locked->ready_at = now(),
                'delivered' => $locked->delivered_at = now(),
                'cancelled' => [$locked->cancelled_at, $locked->cancel_reason] = [now(), mb_substr(trim((string) $note), 0, 300)],
                default => null,
            };
            $locked->lock_version++;
            $locked->save();
            if ($status === 'delivered' && $balance > 0) {
                $note = trim(__('orders.delivered_with_balance', ['balance' => \App\Support\Money::format($balance)], 'ro').' '.$note);
            }
            OrderEvent::create(['order_id' => $locked->id, 'user_id' => $user->id, 'type' => 'status', 'from_status' => $from, 'to_status' => $status,
                'note' => $note ? mb_substr(trim($note), 0, 500) : null, 'created_at' => now()]);
            $this->audit->log('orders.status', $locked, ['from' => $from, 'to' => $status], $user, $locked->location_id);
            // The pickup message (CMD-02) is sent by the communication module when it is configured.
            if ($status === 'ready') {
                event(new OrderReady($locked));
            }

            return $locked;
        });
    }

    private function replaceItems(Order $order, array $items): void
    {
        if (! $items) {
            throw new FinanceException(__('orders.errors.no_items'));
        }
        $order->items()->delete();
        $total = 0;
        foreach (array_values($items) as $position => $item) {
            if (! in_array($item['kind'], OrderItem::KINDS, true) || $item['quantity'] < 1 || $item['unit_price_cents'] < 0) {
                throw new FinanceException(__('orders.errors.item'));
            }
            OrderItem::create([
                'order_id' => $order->id,
                'kind' => $item['kind'],
                'description' => mb_substr(trim($item['description']), 0, 300),
                'quantity' => $item['quantity'],
                'unit_price_cents' => $item['unit_price_cents'],
                'position' => $position,
            ]);
            $total += $item['quantity'] * $item['unit_price_cents'];
        }
        $order->total_cents = $total;
    }
}
