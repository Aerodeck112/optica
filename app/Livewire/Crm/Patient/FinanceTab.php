<?php

namespace App\Livewire\Crm\Patient;

use App\Domain\Finance\ChargeService;
use App\Domain\Finance\FinanceException;
use App\Domain\Finance\Ledger;
use App\Models\Appointment;
use App\Models\Location;
use App\Models\Offering;
use App\Models\Patient;
use App\Models\ServiceCharge;
use App\Support\Access\AccessControl;
use App\Support\Money;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/** Services performed and their payments; orders are listed with their balance (FIN-01, FIN-02). */
class FinanceTab extends Component
{
    #[Locked]
    public int $patientId;

    public bool $adding = false;

    public ?int $appointmentId = null;

    public ?int $locationId = null;

    public string $description = '';

    public string $amount = '';

    public string $performedOn = '';

    #[Locked]
    public ?int $selected = null;

    #[Locked]
    public ?int $voiding = null;

    public string $voidReason = '';

    public function boot(): void
    {
        $this->authorize('payments.view');
    }

    public function startAdd(): void
    {
        $this->authorize('payments.create');
        $this->adding = true;
        $this->performedOn = now(config('optica.clinic_timezone'))->toDateString();
        $this->locationId = $this->locations()->first()?->id;
    }

    /** The price configured for the specialist and service is proposed; staff can change it. */
    public function updatedAppointmentId(): void
    {
        $appointment = $this->appointmentId ? Appointment::with(['service', 'offering'])->where('patient_id', $this->patientId)->find($this->appointmentId) : null;
        if ($appointment) {
            $this->description = (string) $appointment->service?->name();
            $price = $appointment->offering?->price ?? $appointment->service?->default_price;
            $this->amount = $price !== null ? Money::input((int) round((float) $price * 100)) : '';
            $this->performedOn = $appointment->starts_at->timezone(config('optica.clinic_timezone'))->toDateString();
        }
    }

    public function add(ChargeService $charges): void
    {
        $this->authorize('payments.create');
        $this->validate([
            'description' => ['required', 'string', 'max:300'],
            'amount' => ['required', fn ($a, $v, $fail) => Money::isValid($v) && Money::parse($v) >= 0 ? null : $fail(__('finance.errors.amount_invalid'))],
            'performedOn' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'locationId' => ['required_without:appointmentId', 'nullable', 'integer'],
        ], [], ['description' => __('finance.charge_description'), 'amount' => __('finance.amount'), 'performedOn' => __('finance.performed_on')]);
        $appointment = $this->appointmentId ? Appointment::where('patient_id', $this->patientId)->findOrFail($this->appointmentId) : null;
        $location = $appointment?->location_id ?? $this->locationId;
        abort_unless(auth()->user()->can('payments.create', $location), 403);
        try {
            $charge = $charges->record(Patient::findOrFail($this->patientId), $location, $this->description, Money::parse($this->amount), $this->performedOn, auth()->user(), $appointment);
        } catch (FinanceException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }
        $this->reset(['adding', 'appointmentId', 'description', 'amount']);
        $this->selected = $charge->id;
    }

    public function select(int $id): void
    {
        $this->selected = $this->selected === $id ? null : ServiceCharge::where('patient_id', $this->patientId)->findOrFail($id)->id;
    }

    public function startVoid(int $id): void
    {
        $this->authorize('payments.refund');
        $this->voiding = ServiceCharge::where('patient_id', $this->patientId)->findOrFail($id)->id;
        $this->voidReason = '';
    }

    public function cancelVoid(): void
    {
        $this->voiding = null;
    }

    public function void(ChargeService $charges): void
    {
        $this->authorize('payments.refund');
        $charge = ServiceCharge::where('patient_id', $this->patientId)->findOrFail($this->voiding);
        abort_unless(auth()->user()->can('payments.refund', $charge->location_id), 403);
        try {
            $charges->void($charge, $this->voidReason, auth()->user());
        } catch (FinanceException $e) {
            $this->addError('voidReason', $e->getMessage());

            return;
        }
        $this->voiding = null;
    }

    #[On('payments-changed')]
    public function refreshBalances(): void {}

    private function locations()
    {
        $ids = app(AccessControl::class)->locationIdsFor(auth()->user(), 'payments.create');

        return Location::where('is_active', true)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('sort_order')->get();
    }

    public function render(Ledger $ledger)
    {
        $locations = app(AccessControl::class)->locationIdsFor(auth()->user(), 'payments.view');
        $charges = ServiceCharge::with(['location', 'specialist'])->where('patient_id', $this->patientId)
            ->when($locations !== null, fn ($q) => $q->whereIn('location_id', $locations))->latest('performed_on')->latest('id')->get();
        $rows = $charges->map(fn ($c) => ['charge' => $c] + $ledger->summary($c));
        $orders = \App\Models\Order::where('patient_id', $this->patientId)->when($locations !== null, fn ($q) => $q->whereIn('location_id', $locations))->get()
            ->map(fn ($o) => ['order' => $o] + $ledger->summary($o));

        return view('livewire.crm.patient.finance-tab', [
            'rows' => $rows,
            'orders' => $orders,
            'totals' => [
                'value' => $rows->sum('value') + $orders->sum('value'),
                'paid' => $rows->sum('paid') + $orders->sum('paid'),
                'balance' => $rows->sum('balance') + $orders->sum('balance'),
            ],
            'appointments' => $this->adding ? Appointment::with('service')->where('patient_id', $this->patientId)->where('starts_at', '<=', now()->addDay())
                ->whereNotIn('status', ['cancelled', 'expired', 'held'])->orderByDesc('starts_at')->limit(20)->get() : collect(),
            'locations' => $this->adding ? $this->locations() : collect(),
            'tz' => config('optica.clinic_timezone'),
        ]);
    }
}
