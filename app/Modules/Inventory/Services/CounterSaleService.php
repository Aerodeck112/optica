<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Audit\AuditLogger;
use App\Modules\Booking\Models\Booking;
use App\Modules\Finance\Models\FiscalReceipt;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\Fiscal\FiscalReceiptService;
use App\Modules\Finance\Services\VatCalculator;
use App\Modules\Inventory\Models\CounterSale;
use App\Modules\Inventory\Models\Item;
use App\Support\LocalTime;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Vânzarea la recepție a produselor din stoc: scade stocul, înregistrează încasarea (numerar / card POS) și pune
 * bonul fiscal în coada casei de marcat. Stocul, încasarea și vânzarea se salvează împreună (totul sau nimic);
 * bonul se emite imediat după, iar dacă nu se poate (ex. nicio casă activă), vânzarea rămâne și bonul se reemite din listă.
 */
class CounterSaleService
{
    public const METHODS = ['cash' => 'Numerar', 'card_pos' => 'Card (POS)'];

    public function __construct(
        private readonly StockService $stock,
        private readonly VatCalculator $vat,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<int|string, int|float|string>  $quantities  item_id => cantitate
     * @return array{sale: CounterSale, receipt: ?FiscalReceipt, receipt_error: ?string}
     */
    public function checkout(array $quantities, string $method, int $warehouseId, User $user, ?Booking $booking = null, bool $printReceipt = true, ?string $idempotencyKey = null): array
    {
        if (! isset(self::METHODS[$method])) {
            throw new InventoryException('Alegeți metoda de plată: numerar sau card.');
        }
        $quantities = array_filter(array_map(fn ($q) => BigDecimal::of(str_replace(',', '.', (string) $q)), $quantities), fn (BigDecimal $q) => $q->isPositive());
        if ($quantities === []) {
            throw new InventoryException('Adăugați cel puțin un produs.');
        }

        $sale = DB::transaction(function () use ($quantities, $method, $warehouseId, $user, $booking, $idempotencyKey) {
            if ($idempotencyKey && ($existing = CounterSale::query()->where('uuid', $idempotencyKey)->first())) {
                return $existing; // dublu-clic: aceeași vânzare, nu încă una
            }
            $items = Item::query()->whereIn('id', array_keys($quantities))->get()->keyBy('id');
            $today = LocalTime::now();
            $lines = [];
            $total = 0;
            foreach ($quantities as $itemId => $quantity) {
                $item = $items[$itemId] ?? throw new InventoryException('Produsul nu mai există.');
                if (! $item->is_active || ! $item->sale_price_minor) {
                    throw new InventoryException("„{$item->name}” nu are preț de vânzare sau nu este activ.");
                }
                $amount = BigDecimal::of((int) $item->sale_price_minor)->multipliedBy($quantity)->toScale(0, RoundingMode::HalfUp)->toInt();
                $lines[] = [
                    'item' => $item,
                    'quantity' => (string) $quantity->toScale(3),
                    'unit_price_minor' => (int) $item->sale_price_minor,
                    'amount_minor' => $amount,
                    'vat_rate' => $this->vat->rateFor($item->vat_group ?: 'standard', $today)['rate'],
                ];
                $total += $amount;
            }

            $sale = CounterSale::query()->create([
                'uuid' => $idempotencyKey ?: (string) Str::uuid(),
                'warehouse_id' => $warehouseId,
                'booking_id' => $booking?->id,
                'method' => $method,
                'total_minor' => $total,
                'status' => 'completed',
                'created_by' => $user->id,
            ]);
            foreach ($lines as $line) {
                $sale->lines()->create([
                    'item_id' => $line['item']->id,
                    'name' => $line['item']->name,
                    'quantity' => $line['quantity'],
                    'unit_price_minor' => $line['unit_price_minor'],
                    'amount_minor' => $line['amount_minor'],
                    'vat_group' => $line['item']->vat_group ?: 'standard',
                    'vat_rate' => $line['vat_rate'],
                ]);
                if ($line['item']->track_stock) {
                    $this->stock->moveFor($sale, $line['item'], $warehouseId, '-'.$line['quantity'], 'sale', $user->id);
                }
            }
            $payment = Payment::query()->create([
                'uuid' => (string) Str::uuid(),
                'booking_id' => $booking?->id,
                'customer_id' => $booking?->customer_id,
                'method' => $method,
                'purpose' => 'sale',
                'amount_minor' => $total,
                'currency' => 'RON',
                'status' => 'succeeded',
                'succeeded_at' => now(),
                'recorded_by' => $user->id,
                'note' => 'Vânzare produse la recepție',
            ]);
            $sale->forceFill(['payment_id' => $payment->id])->save();
            $this->audit->log('sale.completed', $sale, ['total_minor' => $total, 'method' => $method, 'lines' => count($lines)]);

            return $sale;
        });

        [$receipt, $error] = $printReceipt ? $this->issueReceipt($sale, $user) : [null, null];

        return ['sale' => $sale->fresh(['lines', 'payment']), 'receipt' => $receipt, 'receipt_error' => $error];
    }

    /** Bonul fiscal al vânzării (sau reemiterea lui). Întoarce [bon, mesaj de eroare]. */
    public function issueReceipt(CounterSale $sale, User $user): array
    {
        if (! $sale->payment || $sale->status !== 'completed') {
            return [null, 'Vânzarea este anulată; nu se emite bon.'];
        }
        try {
            $fiscal = app(FiscalReceiptService::class);

            return [$fiscal->forPayment($sale->payment, $fiscal->defaultDevice(), $user->id), null];
        } catch (\Throwable $e) {
            return [null, $e->getMessage()];
        }
    }

    /**
     * Anularea unei vânzări greșite: produsele revin în stoc, încasarea se marchează anulată.
     * Dacă bonul fiscal a fost deja tipărit, anularea lui se face și din casa de marcat (bon de stornare), conform procedurii.
     */
    public function cancel(CounterSale $sale, User $user, string $reason): CounterSale
    {
        if (trim($reason) === '') {
            throw new InventoryException('Motivul anulării este obligatoriu.');
        }

        return DB::transaction(function () use ($sale, $user, $reason) {
            $locked = CounterSale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'completed') {
                throw new InventoryException('Vânzarea este deja anulată.');
            }
            foreach ($locked->lines()->with('item')->get() as $line) {
                if ($line->item?->track_stock) {
                    $this->stock->moveFor($locked, $line->item, $locked->warehouse_id, (string) $line->quantity, 'adjustment', $user->id, 'Anulare vânzare: '.mb_substr($reason, 0, 200));
                }
            }
            $locked->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancel_reason' => mb_substr($reason, 0, 255)])->save();
            $locked->payment?->forceFill(['status' => 'cancelled', 'note' => 'Vânzare anulată: '.mb_substr($reason, 0, 200)])->save();
            $this->audit->log('sale.cancelled', $locked, ['reason' => $reason]);

            return $locked;
        });
    }
}
