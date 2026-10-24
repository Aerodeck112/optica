<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Finance\Models\DocumentSeries;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryCount;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Inventory\Models\PurchaseInvoiceLine;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Stocuri: fiecare modificare este o mișcare (istoric complet); soldul pe gestiune se actualizează sub blocare,
 * deci operațiile simultane nu pierd cantități. Ieșirile nu pot duce stocul sub zero.
 */
class StockService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Recepția (NIR): numerotare din serie, intrări în stoc, cantități recepționate pe factura furnizorului. */
    public function confirmReceipt(GoodsReceipt $receipt, int $userId): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $userId) {
            $receipt = GoodsReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->status !== 'draft') {
                throw new InventoryException('Recepția a fost deja confirmată sau anulată.');
            }
            $lines = $receipt->lines()->with('item')->get();
            if ($lines->isEmpty()) {
                throw new InventoryException('Recepția nu are linii.');
            }

            $receipt->forceFill([
                'number' => $this->nextNumber('goods_receipt', 'NIR'),
                'status' => 'confirmed',
                'received_on' => $receipt->received_on ?? now(config('aw.timezone'))->toDateString(),
                'received_by' => $userId,
                'confirmed_at' => now(),
            ])->save();

            foreach ($lines as $line) {
                if ($line->item->track_stock) {
                    $this->apply($line->item, $receipt->warehouse_id, (string) $line->quantity, 'receipt', $receipt, null, $userId, (string) $line->unit_cost);
                }
                if ($line->purchase_invoice_line_id) {
                    $this->addReceived($line->purchase_invoice_line_id, (string) $line->quantity);
                }
            }
            if ($receipt->purchase_invoice_id) {
                $this->refreshPurchaseStatus($receipt->purchaseInvoice);
            }
            $this->audit->log('stock.receipt_confirmed', $receipt, ['number' => $receipt->number, 'lines' => $lines->count()]);

            return $receipt;
        });
    }

    /** Anularea unei recepții confirmate: mișcări inverse, doar dacă stocul permite. */
    public function cancelReceipt(GoodsReceipt $receipt, int $userId, string $reason): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $userId, $reason) {
            $receipt = GoodsReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->status !== 'confirmed') {
                throw new InventoryException('Doar recepțiile confirmate pot fi anulate.');
            }
            foreach ($receipt->lines()->with('item')->get() as $line) {
                if ($line->item->track_stock) {
                    $this->apply($line->item, $receipt->warehouse_id, (string) BigDecimal::of((string) $line->quantity)->negated(), 'adjustment', $receipt, 'Anulare recepție: '.$reason, $userId);
                }
                if ($line->purchase_invoice_line_id) {
                    $this->addReceived($line->purchase_invoice_line_id, (string) BigDecimal::of((string) $line->quantity)->negated());
                }
            }
            $receipt->forceFill(['status' => 'cancelled', 'notes' => trim(($receipt->notes ? $receipt->notes."\n" : '').'Anulată: '.$reason)])->save();
            if ($receipt->purchase_invoice_id) {
                $this->refreshPurchaseStatus($receipt->purchaseInvoice);
            }
            $this->audit->log('stock.receipt_cancelled', $receipt, ['reason' => $reason]);

            return $receipt;
        });
    }

    /** Consum, casare sau ajustare manuală. Cantitatea e pozitivă; semnul rezultă din tip. */
    public function record(Item $item, int $warehouseId, string $quantity, string $type, int $userId, ?string $note = null): StockMovement
    {
        if (! in_array($type, ['consumption', 'write_off', 'sale', 'adjustment_in', 'adjustment_out'], true)) {
            throw new InventoryException('Tip de mișcare necunoscut.');
        }
        $qty = BigDecimal::of(str_replace(',', '.', $quantity));
        if ($qty->isLessThanOrEqualTo(0)) {
            throw new InventoryException('Cantitatea trebuie să fie pozitivă.');
        }
        $signed = in_array($type, ['adjustment_in'], true) ? $qty : $qty->negated();
        $stored = str_starts_with($type, 'adjustment') ? 'adjustment' : $type;

        return DB::transaction(fn () => $this->apply($item, $warehouseId, (string) $signed, $stored, null, $note, $userId));
    }

    /** Pornește un inventar: cantitățile scriptice actuale, pentru toate articolele urmărite. */
    public function startCount(Warehouse $warehouse, int $userId): InventoryCount
    {
        return DB::transaction(function () use ($warehouse, $userId) {
            $count = InventoryCount::query()->create(['warehouse_id' => $warehouse->id, 'counted_on' => now(config('aw.timezone'))->toDateString(), 'status' => 'draft', 'created_by' => $userId]);
            $levels = StockLevel::query()->where('warehouse_id', $warehouse->id)->pluck('quantity', 'item_id');
            Item::query()->where('is_active', true)->where('track_stock', true)->orderBy('name')->each(function (Item $item) use ($count, $levels) {
                $count->lines()->create(['item_id' => $item->id, 'expected_quantity' => (string) ($levels[$item->id] ?? '0')]);
            });

            return $count;
        });
    }

    /**
     * Confirmă inventarul: diferența față de stocul scriptic de la momentul confirmării devine mișcare „inventory”.
     * Liniile nenumărate (necompletate) nu se modifică.
     */
    public function confirmCount(InventoryCount $count, int $userId): InventoryCount
    {
        return DB::transaction(function () use ($count, $userId) {
            $count = InventoryCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            if ($count->status !== 'draft') {
                throw new InventoryException('Inventarul a fost deja confirmat.');
            }
            $differences = 0;
            foreach ($count->lines()->with('item')->whereNotNull('counted_quantity')->get() as $line) {
                $current = $this->lockLevel($line->item_id, $count->warehouse_id);
                $diff = BigDecimal::of((string) $line->counted_quantity)->minus((string) $current->quantity);
                $line->forceFill(['expected_quantity' => (string) $current->quantity])->save();
                if (! $diff->isZero()) {
                    $this->apply($line->item, $count->warehouse_id, (string) $diff, 'inventory', $count, 'Diferență inventar', $userId, null, allowNegative: true);
                    $differences++;
                }
            }
            $count->forceFill(['status' => 'confirmed', 'confirmed_by' => $userId, 'confirmed_at' => now()])->save();
            $this->audit->log('stock.count_confirmed', $count, ['differences' => $differences]);

            return $count;
        });
    }

    public function level(int $itemId, int $warehouseId): string
    {
        return (string) (StockLevel::query()->where('item_id', $itemId)->where('warehouse_id', $warehouseId)->value('quantity') ?? '0.000');
    }

    /** Propune o recepție (ciornă) din liniile de marfă încă nerecepționate ale unei facturi de furnizor. */
    public function proposeReceipt(PurchaseInvoice $invoice, int $warehouseId, int $userId): GoodsReceipt
    {
        return DB::transaction(function () use ($invoice, $warehouseId, $userId) {
            $lines = $invoice->lines()->whereNotNull('item_id')->where('line_type', '!=', 'service')->get()
                ->filter(fn ($line) => BigDecimal::of((string) $line->quantity)->isGreaterThan((string) $line->received_quantity));
            if ($lines->isEmpty()) {
                throw new InventoryException('Factura nu are linii de marfă asociate unor articole și nerecepționate. Asociați liniile cu articole din catalogul de stoc.');
            }
            $receipt = GoodsReceipt::query()->create([
                'warehouse_id' => $warehouseId, 'supplier_id' => $invoice->supplier_id, 'purchase_invoice_id' => $invoice->id,
                'status' => 'draft', 'origin' => $invoice->source === 'efactura' ? 'efactura_proposal' : 'manual', 'received_by' => $userId,
            ]);
            foreach ($lines as $line) {
                $receipt->lines()->create([
                    'item_id' => $line->item_id,
                    'purchase_invoice_line_id' => $line->id,
                    'quantity' => (string) BigDecimal::of((string) $line->quantity)->minus((string) $line->received_quantity),
                    'unit_cost' => (string) $line->unit_price,
                ]);
            }

            return $receipt;
        });
    }

    private function apply(Item $item, int $warehouseId, string $quantity, string $type, ?Model $reference, ?string $note, int $userId, ?string $unitCost = null, bool $allowNegative = false): StockMovement
    {
        $level = $this->lockLevel($item->id, $warehouseId);
        $new = BigDecimal::of((string) $level->quantity)->plus($quantity);
        if ($new->isNegative() && ! $allowNegative) {
            throw new InventoryException("Stoc insuficient pentru „{$item->name}”: disponibil {$level->quantity}.");
        }
        $level->forceFill(['quantity' => (string) $new])->save();

        return StockMovement::query()->create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouseId,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'type' => $type,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'note' => $note ? mb_substr($note, 0, 255) : null,
            'created_by' => $userId,
            'occurred_at' => now(),
        ]);
    }

    private function addReceived(int $purchaseLineId, string $quantity): void
    {
        $line = PurchaseInvoiceLine::query()->whereKey($purchaseLineId)->lockForUpdate()->first();
        $line?->forceFill(['received_quantity' => (string) BigDecimal::of((string) $line->received_quantity)->plus($quantity)])->save();
    }

    private function lockLevel(int $itemId, int $warehouseId): StockLevel
    {
        DB::table('stock_levels')->insertOrIgnore(['item_id' => $itemId, 'warehouse_id' => $warehouseId, 'quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return StockLevel::query()->where('item_id', $itemId)->where('warehouse_id', $warehouseId)->lockForUpdate()->firstOrFail();
    }

    private function refreshPurchaseStatus(PurchaseInvoice $invoice): void
    {
        if (in_array($invoice->status, ['paid', 'cancelled'], true)) {
            return;
        }
        $goods = $invoice->lines()->where('line_type', '!=', 'service')->get();
        $received = $goods->filter(fn ($line) => BigDecimal::of((string) $line->received_quantity)->isGreaterThan(0));
        $complete = $goods->every(fn ($line) => BigDecimal::of((string) $line->received_quantity)->isGreaterThanOrEqualTo((string) $line->quantity));
        $invoice->forceFill(['status' => $goods->isNotEmpty() && $complete ? 'received' : ($received->isNotEmpty() ? 'partially_received' : 'registered')])->save();
    }

    /** Următorul număr dintr-o serie; seria implicită se creează la prima utilizare. */
    private function nextNumber(string $type, string $defaultPrefix): string
    {
        $series = DocumentSeries::query()->where('document_type', $type)->where('is_active', true)->orderByDesc('is_default')->lockForUpdate()->first()
            ?? DocumentSeries::query()->create(['document_type' => $type, 'prefix' => $defaultPrefix, 'next_number' => 1, 'padding' => 5, 'is_default' => true, 'is_active' => true]);
        $number = (int) $series->next_number;
        $series->forceFill(['next_number' => $number + 1])->save();

        return $series->prefix.str_pad((string) $number, (int) $series->padding, '0', STR_PAD_LEFT);
    }
}
