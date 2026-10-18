<?php

namespace App\Livewire\Crm;

use App\Domain\Finance\Ledger;
use App\Models\Order;
use App\Support\Access\AccessControl;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Glasses orders in the user's locations, with the delayed list (CMD-03). */
class OrderIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'open';

    #[Url]
    public bool $delayed = false;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public ?int $location = null;

    public function boot(): void
    {
        $this->authorize('orders.view');
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public static function scoped(): Builder
    {
        $locations = app(AccessControl::class)->locationIdsFor(auth()->user(), 'orders.view');

        return Order::query()->when($locations !== null, fn ($q) => $q->whereIn('location_id', $locations));
    }

    public function render(Ledger $ledger)
    {
        $term = trim($this->search);
        $orders = self::scoped()->with(['patient', 'location', 'responsible'])
            ->when($this->status === 'open', fn ($q) => $q->whereNotIn('status', ['delivered', 'cancelled']))
            ->when(in_array($this->status, Order::STATUSES, true), fn ($q) => $q->where('status', $this->status))
            ->when($this->delayed, fn ($q) => $q->delayed())
            ->when($this->location, fn ($q) => $q->where('location_id', $this->location))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('number', 'like', '%'.$term.'%')
                ->orWhereHas('patient', fn ($p) => $p->where('last_name', 'like', $term.'%')->orWhere('first_name', 'like', $term.'%')->orWhere('code', $term))))
            ->orderByRaw('due_on is null')->orderBy('due_on')->orderByDesc('id')
            ->paginate(30);

        return view('livewire.crm.order-index', [
            'orders' => $orders,
            'balances' => auth()->user()->can('payments.view') ? $orders->getCollection()->mapWithKeys(fn ($o) => [$o->id => $ledger->balanceCents($o)]) : collect(),
            'delayedCount' => self::scoped()->delayed()->count(),
            'locations' => \App\Models\Location::whereIn('id', self::scoped()->distinct()->pluck('location_id'))->orderBy('sort_order')->get(),
        ])->layout('components.layouts.crm')->title(__('orders.title'));
    }
}
