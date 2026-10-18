<?php

namespace App\Livewire\Crm;

use App\Domain\Clinical\ClinicalAccess;
use App\Domain\Finance\FinanceException;
use App\Domain\Orders\OrderService;
use App\Domain\Patients\PatientRegistry;
use App\Models\Appointment;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A glasses order (CMD-01…03): patient, location, responsible, prescription reference, items,
 * estimated date, status history, payments and the handover document.
 */
class OrderEditor extends Component
{
    #[Locked]
    public ?int $orderId = null;

    #[Locked]
    public int $lockVersion = 1;

    #[Url(as: 'patient')]
    public ?int $patientId = null;

    public string $patientQuery = '';

    public ?int $locationId = null;

    public ?int $responsibleId = null;

    public ?int $prescriptionId = null;

    public ?int $appointmentId = null;

    public string $dueOn = '';

    public string $notes = '';

    public array $items = [];

    public string $nextStatus = '';

    public string $statusNote = '';

    public bool $allowUnpaid = false;

    public ?string $notice = null;

    public ?string $failure = null;

    public function boot(): void
    {
        $this->authorize('orders.view');
    }

    public function mount(?Order $order = null): void
    {
        if ($order?->exists) {
            abort_unless(auth()->user()->can('orders.view', $order->location_id), 403);
            $this->orderId = $order->id;
            $this->load($order);
        } else {
            $this->authorize('orders.manage');
            $this->responsibleId = auth()->id();
            $this->locationId = $this->locations()->first()?->id;
            $this->items = [['kind' => 'frame', 'description' => '', 'quantity' => 1, 'price' => ''], ['kind' => 'lens', 'description' => '', 'quantity' => 2, 'price' => '']];
            if ($this->patientId && ! Patient::whereNull('merged_into_id')->whereKey($this->patientId)->exists()) {
                $this->patientId = null;
            }
        }
    }

    private function load(Order $order): void
    {
        $order->load('items');
        $this->patientId = $order->patient_id;
        $this->locationId = $order->location_id;
        $this->responsibleId = $order->responsible_id;
        $this->prescriptionId = $order->prescription_id;
        $this->appointmentId = $order->appointment_id;
        $this->dueOn = $order->due_on?->toDateString() ?? '';
        $this->notes = (string) $order->notes;
        $this->items = $order->items->map(fn (OrderItem $i) => ['kind' => $i->kind, 'description' => $i->description, 'quantity' => $i->quantity, 'price' => Money::input($i->unit_price_cents)])->all();
        $this->lockVersion = $order->lock_version;
        $this->nextStatus = '';
        $this->statusNote = '';
        $this->allowUnpaid = false;
    }

    private function order(): ?Order
    {
        if (! $this->orderId) {
            return null;
        }
        $order = Order::findOrFail($this->orderId);
        abort_unless(auth()->user()->can('orders.view', $order->location_id), 403);

        return $order;
    }

    private function locations()
    {
        $user = auth()->user();
        $ids = app(\App\Support\Access\AccessControl::class)->locationIdsFor($user, 'orders.manage');

        return Location::where('is_active', true)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('sort_order')->get();
    }

    public function choosePatient(int $id): void
    {
        abort_if($this->orderId, 422);
        $this->patientId = Patient::whereNull('merged_into_id')->findOrFail($id)->id;
        $this->patientQuery = '';
        $this->prescriptionId = $this->appointmentId = null;
    }

    public function addItem(): void
    {
        abort_if(count($this->items) >= 30, 422);
        $this->items[] = ['kind' => 'other', 'description' => '', 'quantity' => 1, 'price' => ''];
    }

    public function removeItem(int $index): void
    {
        array_splice($this->items, $index, 1);
    }

    public function save(OrderService $service): void
    {
        $this->authorize('orders.manage');
        $this->notice = $this->failure = null;
        $this->validate([
            'patientId' => ['required', 'integer', Rule::exists('patients', 'id')->whereNull('merged_into_id')],
            'locationId' => ['required', 'integer', Rule::in($this->locations()->pluck('id'))],
            'responsibleId' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'prescriptionId' => ['nullable', 'integer', Rule::exists('prescriptions', 'id')->where('patient_id', $this->patientId)],
            'appointmentId' => ['nullable', 'integer', Rule::exists('appointments', 'id')->where('patient_id', $this->patientId)],
            'dueOn' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.kind' => ['required', Rule::in(OrderItem::KINDS)],
            'items.*.description' => ['required', 'string', 'max:300'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.price' => ['required', fn ($a, $v, $fail) => Money::isValid($v) && Money::parse($v) >= 0 ? null : $fail(__('finance.errors.amount_invalid'))],
        ], [], [
            'patientId' => __('orders.patient'), 'locationId' => __('crm.fields.location'), 'dueOn' => __('orders.due_on'),
            'items.*.description' => __('orders.item_description'), 'items.*.price' => __('orders.unit_price'), 'items.*.quantity' => __('orders.quantity'),
        ]);

        $data = [
            'location_id' => $this->locationId,
            'responsible_id' => $this->responsibleId,
            'prescription_id' => $this->prescriptionId,
            'appointment_id' => $this->appointmentId,
            'due_on' => $this->dueOn ?: null,
            'notes' => trim($this->notes) ?: null,
        ];
        $items = array_map(fn ($i) => ['kind' => $i['kind'], 'description' => $i['description'], 'quantity' => (int) $i['quantity'], 'unit_price_cents' => Money::parse($i['price'])], $this->items);

        try {
            if ($this->orderId) {
                $order = $service->update($this->order(), $data, $items, $this->lockVersion, auth()->user());
                $this->load($order);
                $this->notice = __('orders.saved');
            } else {
                $order = $service->create(Patient::findOrFail($this->patientId), $data, $items, auth()->user());
                session()->flash('status', __('orders.saved'));
                $this->redirectRoute('crm.orders.edit', $order, navigate: true);
            }
        } catch (FinanceException $e) {
            $this->failure = $e->getMessage();
        }
    }

    public function changeStatus(OrderService $service): void
    {
        $this->authorize('orders.change_status');
        $this->notice = $this->failure = null;
        abort_unless(in_array($this->nextStatus, Order::STATUSES, true), 422);
        try {
            $order = $service->changeStatus($this->order(), $this->nextStatus, auth()->user(), $this->statusNote ?: null, $this->allowUnpaid);
            $this->load($order);
            $this->notice = __('orders.status_changed');
        } catch (FinanceException $e) {
            $this->failure = $e->getMessage();
        }
    }

    public function render(PatientRegistry $registry, ClinicalAccess $access)
    {
        $order = $this->order()?->load(['events.user', 'patient', 'location']);
        $patient = $this->patientId ? Patient::find($this->patientId) : null;
        $user = auth()->user();
        $prescriptions = $patient ? Prescription::with('specialist')->where('patient_id', $patient->id)->whereIn('status', ['active', 'superseded'])->latest('issued_on')->get() : collect();
        $linked = $prescriptions->firstWhere('id', $this->prescriptionId);
        $total = 0;
        foreach ($this->items as $i) {
            $total += (Money::isValid($i['price'] ?? '') ? Money::parse($i['price']) : 0) * max(0, (int) ($i['quantity'] ?? 0));
        }

        return view('livewire.crm.order-editor', [
            'order' => $order,
            'patient' => $patient,
            'patientResults' => ! $patient ? $registry->search($this->patientQuery, 6) : collect(),
            'locations' => $this->locations(),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'prescriptions' => $prescriptions,
            // Values only for people allowed to see clinical data (CMD-01).
            'rxValues' => $linked && $access->canViewPrescription($user, $linked) ? $linked : null,
            'appointments' => $patient ? Appointment::with('service')->where('patient_id', $patient->id)->where('starts_at', '>=', now()->subMonths(6))->orderByDesc('starts_at')->limit(20)->get() : collect(),
            'editable' => ! $order || $order->isOpen(),
            'total' => $total,
            'tz' => config('optica.clinic_timezone'),
        ])->layout('components.layouts.crm')->title($order ? $order->number : __('orders.create'));
    }
}
