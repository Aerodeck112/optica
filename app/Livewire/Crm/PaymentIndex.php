<?php

namespace App\Livewire\Crm;

use App\Models\Order;
use App\Models\Payment;
use App\Models\ServiceCharge;
use App\Support\Access\AccessControl;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Cash journal per period and location (FIN-01, FIN-02): money received is shown apart from the
 * value of services performed and orders delivered. Voided entries stay visible, struck through.
 */
class PaymentIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public ?int $location = null;

    #[Url]
    public string $method = '';

    public function boot(): void
    {
        $this->authorize('payments.view');
    }

    public function mount(): void
    {
        $today = now(config('optica.clinic_timezone'))->toDateString();
        $this->from = $this->validDate($this->from) ?? $today;
        $this->to = $this->validDate($this->to) ?? $today;
    }

    private function validDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $tz = config('optica.clinic_timezone');
        $from = CarbonImmutable::parse($this->validDate($this->from) ?? 'today', $tz)->startOfDay();
        $to = CarbonImmutable::parse($this->validDate($this->to) ?? 'today', $tz)->endOfDay();
        if ($to < $from) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }
        $locations = app(AccessControl::class)->locationIdsFor(auth()->user(), 'payments.view');
        $scope = fn ($q) => $q->when($locations !== null, fn ($q) => $q->whereIn('location_id', $locations))
            ->when($this->location, fn ($q) => $q->where('location_id', $this->location));

        $payments = Payment::query()->tap($scope)->whereBetween('received_at', [$from->utc(), $to->utc()])
            ->when(in_array($this->method, Payment::METHODS, true), fn ($q) => $q->where('method', $this->method));

        $byMethod = (clone $payments)->valid()->selectRaw("method, sum(case when type = 'refund' then -amount_cents else amount_cents end) as net")
            ->groupBy('method')->pluck('net', 'method')->map(fn ($v) => (int) $v);

        return view('livewire.crm.payment-index', [
            'rows' => (clone $payments)->with(['patient', 'location', 'operator', 'payable'])->orderByDesc('received_at')->paginate(50),
            'byMethod' => $byMethod,
            'net' => $byMethod->sum(),
            'servicesValue' => (int) ServiceCharge::query()->tap($scope)->valid()->whereBetween('performed_on', [$from->toDateString(), $to->toDateString()])->sum('amount_cents'),
            'ordersValue' => (int) Order::query()->tap($scope)->where('status', 'delivered')->whereBetween('delivered_at', [$from->utc(), $to->utc()])->sum('total_cents'),
            'locations' => \App\Models\Location::when($locations !== null, fn ($q) => $q->whereIn('id', $locations))->orderBy('sort_order')->get(),
            'tz' => $tz,
        ])->layout('components.layouts.crm')->title(__('finance.title'));
    }
}
