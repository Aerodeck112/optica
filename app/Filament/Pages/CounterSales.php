<?php

namespace App\Filament\Pages;

use App\Modules\Booking\Models\Booking;
use App\Modules\Finance\Models\FiscalReceipt;
use App\Modules\Finance\Services\Fiscal\FiscalReceiptService;
use App\Modules\Inventory\Models\CounterSale;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\CounterSaleService;
use App\Modules\Inventory\Services\InventoryException;
use App\Support\LocalTime;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;

/**
 * Vânzare produse la recepție: băuturi, gustări sau orice articol din stoc cu preț de vânzare.
 * Alegi produsele, metoda de plată, apeși „Încasează”: stocul scade, încasarea se înregistrează și bonul pleacă la casa de marcat.
 */
class CounterSales extends Page
{
    protected string $view = 'filament.pages.counter-sales';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static string|\UnitEnum|null $navigationGroup = 'Recepție';

    protected static ?string $navigationLabel = 'Vânzare produse';

    protected static ?string $title = 'Vânzare produse (bar și recepție)';

    protected static ?int $navigationSort = 4;

    /** @var array<int|string, int|string> item_id => cantitate */
    public array $cart = [];

    public string $method = 'cash';

    public ?int $warehouseId = null;

    public ?int $bookingId = null;

    public bool $printReceipt = true;

    public string $search = '';

    public string $checkoutKey = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('finance.issue');
    }

    public function mount(): void
    {
        $this->warehouseId = $this->defaultWarehouseId();
        $this->printReceipt = app(FiscalReceiptService::class)->defaultDevice() !== null;
        $this->checkoutKey = (string) Str::uuid();
    }

    public function add(int $itemId): void
    {
        $this->cart[$itemId] = (int) ($this->cart[$itemId] ?? 0) + 1;
    }

    public function decrement(int $itemId): void
    {
        $qty = (int) ($this->cart[$itemId] ?? 0) - 1;
        if ($qty <= 0) {
            unset($this->cart[$itemId]);
        } else {
            $this->cart[$itemId] = $qty;
        }
    }

    public function remove(int $itemId): void
    {
        unset($this->cart[$itemId]);
    }

    public function clear(): void
    {
        $this->cart = [];
        $this->bookingId = null;
    }

    public function checkout(): void
    {
        abort_unless(static::canAccess(), 403);
        try {
            $result = app(CounterSaleService::class)->checkout(
                $this->cart, $this->method, (int) $this->warehouseId, auth()->user(),
                $this->bookingId ? Booking::query()->find($this->bookingId) : null, $this->printReceipt, $this->checkoutKey,
            );
        } catch (InventoryException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        $sale = $result['sale'];
        $title = 'Încasat '.money($sale->total_minor).' ('.CounterSaleService::METHODS[$sale->method].').';
        if ($result['receipt_error']) {
            Notification::make()->warning()->persistent()->title($title.' Bonul fiscal nu s-a emis.')
                ->body($result['receipt_error'].' Îl puteți reemite din lista vânzărilor de azi.')->send();
        } else {
            Notification::make()->success()->title($title.($result['receipt'] ? ' Bonul a fost trimis la casa de marcat.' : ''))->send();
        }
        $this->cart = [];
        $this->bookingId = null;
        $this->checkoutKey = (string) Str::uuid();
    }

    public function reissue(int $saleId): void
    {
        abort_unless(static::canAccess(), 403);
        [$receipt, $error] = app(CounterSaleService::class)->issueReceipt(CounterSale::query()->with('payment')->findOrFail($saleId), auth()->user());
        $error
            ? Notification::make()->danger()->title($error)->send()
            : Notification::make()->success()->title('Bonul a fost trimis la casa de marcat.')->send();
    }

    public function cancelSale(int $saleId, string $reason = ''): void
    {
        abort_unless(auth()->user()?->can('finance.refund'), 403);
        try {
            $sale = app(CounterSaleService::class)->cancel(CounterSale::query()->findOrFail($saleId), auth()->user(), $reason);
        } catch (InventoryException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        $printed = FiscalReceipt::query()->where('payment_id', $sale->payment_id)->whereIn('status', ['printed', 'reconciled_printed'])->exists();
        Notification::make()->success()->persistent()->title('Vânzarea a fost anulată; produsele au revenit în stoc.')
            ->body($printed ? 'Bonul fiscal fusese tipărit: anulați-l și din casa de marcat (bon de stornare), conform procedurii.' : null)->send();
    }

    protected function getViewData(): array
    {
        $items = Item::query()->where('is_active', true)->whereNotNull('sale_price_minor')->where('sale_price_minor', '>', 0)
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.trim($this->search).'%')->orWhere('sku', 'like', '%'.trim($this->search).'%')))
            ->with('unit')->orderBy('name')->get();
        $levels = StockLevel::query()->where('warehouse_id', $this->warehouseId)->whereIn('item_id', $items->pluck('id'))->pluck('quantity', 'item_id');
        $cartItems = Item::query()->whereIn('id', array_keys($this->cart))->get()->keyBy('id');
        $lines = collect($this->cart)->map(fn ($qty, $id) => isset($cartItems[$id]) ? ['item' => $cartItems[$id], 'qty' => (int) $qty, 'amount' => (int) $cartItems[$id]->sale_price_minor * (int) $qty] : null)->filter();
        $today = LocalTime::now()->toDateString();

        return [
            'items' => $items,
            'levels' => $levels,
            'lines' => $lines,
            'total' => (int) $lines->sum('amount'),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(),
            'bookings' => Booking::query()->onLocalDate($today)->whereIn('status', ['confirmed', 'checked_in', 'completed'])->orderBy('starts_at')->get(),
            'sales' => CounterSale::query()->with(['lines', 'seller'])->where('created_at', '>=', LocalTime::fromLocal($today, '00:00'))->latest('id')->limit(30)->get(),
            'receipts' => FiscalReceipt::query()->whereIn('payment_id', CounterSale::query()->where('created_at', '>=', LocalTime::fromLocal($today, '00:00'))->pluck('payment_id')->filter())->get()->groupBy('payment_id'),
            'hasDevice' => app(FiscalReceiptService::class)->defaultDevice() !== null,
            'canCancel' => (bool) auth()->user()?->can('finance.refund'),
            'methods' => CounterSaleService::METHODS,
        ];
    }

    private function defaultWarehouseId(): ?int
    {
        $warehouses = Warehouse::query()->where('is_active', true)->get();

        return ($warehouses->firstWhere('type', 'merchandise') ?? $warehouses->first())?->id;
    }
}
