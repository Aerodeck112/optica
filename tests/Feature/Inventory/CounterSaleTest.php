<?php

namespace Tests\Feature\Inventory;

use App\Filament\Pages\CounterSales;
use App\Models\User;
use App\Modules\Catalog\Models\VatRate;
use App\Modules\Finance\Models\FiscalDevice;
use App\Modules\Finance\Models\FiscalReceipt;
use App\Modules\Finance\Models\Payment;
use App\Modules\Inventory\Models\CounterSale;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CounterSaleService;
use App\Modules\Inventory\Services\InventoryException;
use App\Modules\Inventory\Services\StockService;
use Database\Seeders\BaseDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CounterSaleTest extends TestCase
{
    use RefreshDatabase;

    private User $reception;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BaseDataSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        VatRate::query()->firstOrCreate(['group' => 'standard', 'valid_from' => '2025-08-01'], ['name' => 'TVA standard (test)', 'rate' => 21, 'category_code' => 'S']);
        $this->reception = User::factory()->create(['is_active' => true]);
        $this->reception->assignRole('reception');
        $this->warehouse = Warehouse::query()->where('is_active', true)->where('type', 'merchandise')->firstOrFail();
        FiscalDevice::query()->create(['name' => 'Casa recepție', 'driver' => 'agent', 'is_active' => true, 'agent_key_id' => 'fa_test', 'agent_secret' => 'secret-de-test', 'vat_mapping' => ['21' => 'A'], 'payment_mapping' => ['cash' => '1', 'card_pos' => '2']]);
    }

    private function drink(string $name, int $price, ?int $stock = 10): Item
    {
        $item = Item::query()->create(['sku' => 'T-'.uniqid(), 'name' => $name, 'type' => $stock === null ? 'service' : 'merchandise', 'unit_id' => Unit::query()->value('id'),
            'track_stock' => $stock !== null, 'sale_price_minor' => $price, 'vat_group' => 'standard', 'is_active' => true]);
        if ($stock) {
            app(StockService::class)->record($item, $this->warehouse->id, (string) $stock, 'adjustment_in', $this->reception->id);
        }

        return $item;
    }

    public function test_a_sale_lowers_stock_records_the_payment_and_queues_a_receipt_with_each_product(): void
    {
        $cola = $this->drink('Cola 0,5 l (fictiv)', 1000);
        $coffee = $this->drink('Cafea (fictiv)', 900, null);

        $result = app(CounterSaleService::class)->checkout([$cola->id => 2, $coffee->id => 1], 'card_pos', $this->warehouse->id, $this->reception);

        $this->assertSame(2900, $result['sale']->total_minor);
        $this->assertSame('8.000', app(StockService::class)->level($cola->id, $this->warehouse->id));
        $this->assertSame(1, StockMovement::query()->where('type', 'sale')->count(), 'produsele fără stoc urmărit nu fac mișcări');
        $this->assertSame(['sale', 'card_pos', 2900, 'succeeded'], [$result['sale']->payment->purpose, $result['sale']->payment->method, $result['sale']->payment->amount_minor, $result['sale']->payment->status]);
        $this->assertSame([['Cola 0,5 l (fictiv)', '2', 2000], ['Cafea (fictiv)', '1', 900]],
            array_map(fn ($i) => [$i['name'], $i['quantity'], $i['amount_minor']], $result['receipt']->items));
        $this->assertSame('queued', $result['receipt']->status);
    }

    public function test_insufficient_stock_blocks_the_whole_sale_and_a_double_click_does_not_sell_twice(): void
    {
        $cola = $this->drink('Cola 0,5 l (fictiv)', 1000, 1);

        try {
            app(CounterSaleService::class)->checkout([$cola->id => 2], 'cash', $this->warehouse->id, $this->reception);
            $this->fail('Vânzarea trebuia refuzată.');
        } catch (InventoryException $e) {
            $this->assertStringContainsString('Stoc insuficient', $e->getMessage());
        }
        $this->assertSame([0, 0], [CounterSale::query()->count(), Payment::query()->count()]);

        $key = 'f4c1a6a0-0000-4000-8000-000000000001';
        app(CounterSaleService::class)->checkout([$cola->id => 1], 'cash', $this->warehouse->id, $this->reception, idempotencyKey: $key);
        app(CounterSaleService::class)->checkout([$cola->id => 1], 'cash', $this->warehouse->id, $this->reception, idempotencyKey: $key);
        $this->assertSame([1, '0.000'], [CounterSale::query()->count(), app(StockService::class)->level($cola->id, $this->warehouse->id)]);
    }

    public function test_reception_sells_from_the_page_and_only_a_manager_can_cancel_a_sale_back_into_stock(): void
    {
        $water = $this->drink('Apă plată (fictiv)', 800);

        Livewire::actingAs($this->reception)->test(CounterSales::class)
            ->call('add', $water->id)->call('add', $water->id)->set('method', 'cash')->call('checkout')
            ->assertNotified()->assertSet('cart', [])->assertSee('Apă plată (fictiv) × 2')->assertDontSee('Anulează');
        $sale = CounterSale::query()->sole();
        $this->assertSame(1, FiscalReceipt::query()->where('payment_id', $sale->payment_id)->count());

        $manager = User::factory()->create(['is_active' => true]);
        $manager->assignRole('manager');
        Livewire::actingAs($manager)->test(CounterSales::class)->call('cancelSale', $sale->id, 'Produs greșit');
        $this->assertSame(['cancelled', 'cancelled', '10.000'], [$sale->fresh()->status, $sale->payment->fresh()->status, app(StockService::class)->level($water->id, $this->warehouse->id)]);

        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole('operator');
        $this->actingAs($operator)->get(CounterSales::getUrl())->assertForbidden();
    }

    public function test_the_sales_page_warns_when_the_cash_register_agent_is_not_running(): void
    {
        Livewire::actingAs($this->reception)->test(CounterSales::class)->assertSee('Agentul casei de marcat nu rulează (niciodată)');

        FiscalDevice::query()->update(['last_seen_at' => now()]);
        Livewire::actingAs($this->reception)->test(CounterSales::class)->assertDontSee('Agentul casei de marcat nu rulează');
    }
}
