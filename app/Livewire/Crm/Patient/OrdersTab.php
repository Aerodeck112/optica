<?php

namespace App\Livewire\Crm\Patient;

use App\Domain\Finance\Ledger;
use App\Models\Order;
use App\Support\Access\AccessControl;
use Livewire\Attributes\Locked;
use Livewire\Component;

class OrdersTab extends Component
{
    #[Locked]
    public int $patientId;

    public function boot(): void
    {
        $this->authorize('orders.view');
    }

    public function render(Ledger $ledger)
    {
        $locations = app(AccessControl::class)->locationIdsFor(auth()->user(), 'orders.view');
        $orders = Order::with('location')->where('patient_id', $this->patientId)
            ->when($locations !== null, fn ($q) => $q->whereIn('location_id', $locations))->latest('id')->get();

        return view('livewire.crm.patient.orders-tab', [
            'orders' => $orders,
            'balances' => auth()->user()->can('payments.view') ? $orders->mapWithKeys(fn ($o) => [$o->id => $ledger->balanceCents($o)]) : collect(),
        ]);
    }
}
