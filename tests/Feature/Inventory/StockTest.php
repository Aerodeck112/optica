<?php

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Finance\Models\EfacturaInboxMessage;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryException;
use App\Modules\Inventory\Services\PurchaseInvoiceService;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\BaseDataSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\RunsParallelProcesses;
use Tests\TestCase;

class StockTest extends TestCase
{
    use DatabaseMigrations, RunsParallelProcesses;

    private User $user;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BaseDataSeeder::class);
        $this->user = User::factory()->create();
        $this->warehouse = Warehouse::query()->where('code', 'CONS')->firstOrFail();
    }

    private function item(string $name = 'Măști igienice VR (fictiv)'): Item
    {
        return Item::query()->create(['sku' => 'T-'.uniqid(), 'name' => $name, 'type' => 'consumable', 'unit_id' => Unit::query()->where('code', 'H87')->value('id'), 'track_stock' => true, 'is_active' => true]);
    }

    private function supplier(): Supplier
    {
        return Supplier::query()->create(['name' => 'Furnizor Test SRL', 'tax_id' => 'RO76543210']);
    }

    public function test_purchase_invoice_to_receipt_puts_goods_in_stock_and_numbers_the_nir(): void
    {
        $item = $this->item();
        $invoice = app(PurchaseInvoiceService::class)->register($this->supplier(), ['number' => 'F-100', 'issue_date' => '2026-10-20'], [
            ['description' => 'Măști igienice', 'quantity' => '100', 'unit_price' => '1.50', 'vat_rate' => '21', 'item_id' => $item->id],
            ['description' => 'Transport', 'quantity' => '1', 'unit_price' => '20', 'vat_rate' => '21', 'line_type' => 'service'],
        ], $this->user->id);

        $this->assertSame(17000, $invoice->net_total_minor);
        $this->assertSame(20570, $invoice->gross_total_minor);

        $receipt = app(StockService::class)->proposeReceipt($invoice, $this->warehouse->id, $this->user->id);
        $this->assertCount(1, $receipt->lines, 'Serviciile nu intră în recepție.');
        app(StockService::class)->confirmReceipt($receipt, $this->user->id);

        $this->assertSame('NIR00001', $receipt->fresh()->number);
        $this->assertSame('100.000', app(StockService::class)->level($item->id, $this->warehouse->id));
        $this->assertSame('received', $invoice->fresh()->status);

        $this->expectException(InventoryException::class);
        app(StockService::class)->proposeReceipt($invoice->fresh(), $this->warehouse->id, $this->user->id); // nimic rămas de recepționat
    }

    public function test_consumption_cannot_take_stock_below_zero_and_every_change_is_a_movement(): void
    {
        $item = $this->item();
        $stock = app(StockService::class);
        $stock->record($item, $this->warehouse->id, '5', 'adjustment_in', $this->user->id, 'Sold inițial');
        $stock->record($item, $this->warehouse->id, '3', 'consumption', $this->user->id);

        try {
            $stock->record($item, $this->warehouse->id, '3', 'consumption', $this->user->id);
            $this->fail('Stocul nu poate deveni negativ.');
        } catch (InventoryException) {
        }

        $this->assertSame('2.000', $stock->level($item->id, $this->warehouse->id));
        $this->assertSame(2, StockMovement::query()->where('item_id', $item->id)->count());
    }

    public function test_inventory_count_books_the_difference_against_current_stock(): void
    {
        $item = $this->item();
        $stock = app(StockService::class);
        $stock->record($item, $this->warehouse->id, '10', 'adjustment_in', $this->user->id);
        $count = $stock->startCount($this->warehouse, $this->user->id);
        $line = $count->lines()->where('item_id', $item->id)->first();
        $this->assertSame('10.000', (string) $line->expected_quantity);

        $stock->record($item, $this->warehouse->id, '1', 'consumption', $this->user->id); // consum după pornirea inventarului
        $line->update(['counted_quantity' => '7']);
        $stock->confirmCount($count, $this->user->id);

        $this->assertSame('7.000', $stock->level($item->id, $this->warehouse->id));
        $this->assertSame('-2.000', (string) StockMovement::query()->where('type', 'inventory')->value('quantity'));
        $this->assertSame('confirmed', $count->fresh()->status);
    }

    public function test_cancelling_a_receipt_reverses_stock_only_if_available(): void
    {
        $item = $this->item();
        $receipt = GoodsReceipt::query()->create(['warehouse_id' => $this->warehouse->id, 'status' => 'draft']);
        $receipt->lines()->create(['item_id' => $item->id, 'quantity' => '4', 'unit_cost' => '2']);
        $stock = app(StockService::class);
        $stock->confirmReceipt($receipt, $this->user->id);
        $stock->record($item, $this->warehouse->id, '3', 'consumption', $this->user->id);

        $this->expectException(InventoryException::class);
        $stock->cancelReceipt($receipt, $this->user->id, 'Greșeală');
    }

    public function test_efactura_inbox_message_becomes_a_purchase_invoice_with_supplier_matched_by_cui(): void
    {
        $supplier = $this->supplier();
        $message = EfacturaInboxMessage::query()->create([
            'anaf_message_id' => '3000000001', 'message_type' => 'FACTURA PRIMITA', 'issuer_tax_id' => '76543210', 'status' => 'downloaded',
            'parsed' => ['number' => 'FX-7', 'issue_date' => '2026-10-21', 'supplier_name' => 'Furnizor Test SRL', 'supplier_tax_id' => 'RO76543210', 'total' => '121.00', 'net' => '100.00', 'currency' => 'RON',
                'lines' => [['name' => 'Lavete', 'quantity' => '10', 'unit' => 'H87', 'net' => '100.00', 'vat_rate' => '21']]],
        ]);

        $invoice = app(PurchaseInvoiceService::class)->fromInbox($message, $this->user->id);

        $this->assertTrue($invoice->supplier->is($supplier));
        $this->assertSame(12100, $invoice->gross_total_minor);
        $this->assertSame('10.0000', (string) $invoice->lines->first()->unit_price);
        $this->assertSame('linked', $message->fresh()->status);
        $this->expectException(InventoryException::class);
        app(PurchaseInvoiceService::class)->fromInbox($message->fresh(), $this->user->id);
    }

    public function test_parallel_consumption_never_oversells_stock(): void
    {
        $item = $this->item();
        app(StockService::class)->record($item, $this->warehouse->id, '3', 'adjustment_in', $this->user->id);

        $results = $this->runInParallel(array_map(fn ($i) => ['stock', $item->id, (string) $this->warehouse->id, 'web', "s{$i}"], range(1, 6)));

        $this->assertCount(3, array_filter($results, fn ($r) => $r['ok'] ?? false), json_encode($results));
        $this->assertSame('0.000', app(StockService::class)->level($item->id, $this->warehouse->id));
    }
}
