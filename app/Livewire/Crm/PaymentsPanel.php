<?php

namespace App\Livewire\Crm;

use App\Domain\Finance\FinanceException;
use App\Domain\Finance\Ledger;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Value, payments and balance of one order or service charge (FIN-01, FIN-02). Each submission
 * carries a one-time key, so a double click never records the same money twice.
 */
class PaymentsPanel extends Component
{
    #[Locked]
    public string $payableType;

    #[Locked]
    public int $payableId;

    #[Locked]
    public string $token = '';

    public ?string $mode = null;

    public string $amount = '';

    public string $method = 'card';

    public string $reference = '';

    public string $note = '';

    #[Locked]
    public ?int $voiding = null;

    public string $voidReason = '';

    public ?string $notice = null;

    public function boot(): void
    {
        $this->authorize('payments.view');
    }

    public function mount(string $payableType, int $payableId): void
    {
        abort_unless(in_array($payableType, ['order', 'service_charge'], true), 404);
        $this->payableType = $payableType;
        $this->payableId = $payableId;
        $this->authorizeLocation();
    }

    private function payable()
    {
        return Ledger::payableFor($this->payableType, $this->payableId);
    }

    private function authorizeLocation(string $permission = 'payments.view'): void
    {
        abort_unless(auth()->user()->can($permission, $this->payable()->location_id), 403);
    }

    public function open(string $mode, Ledger $ledger): void
    {
        abort_unless(in_array($mode, ['payment', 'refund'], true), 422);
        $this->authorizeLocation($mode === 'payment' ? 'payments.create' : 'payments.refund');
        $this->mode = $mode;
        $this->token = bin2hex(random_bytes(16));
        $summary = $ledger->summary($this->payable());
        $this->amount = Money::input($mode === 'payment' ? max(0, $summary['balance']) : max(0, min($summary['paid'], -$summary['balance'])));
        $this->reference = $this->note = '';
        $this->resetErrorBag();
    }

    public function save(Ledger $ledger): void
    {
        $this->authorizeLocation($this->mode === 'refund' ? 'payments.refund' : 'payments.create');
        $this->validate([
            'amount' => ['required', fn ($a, $v, $fail) => Money::isValid($v) ? null : $fail(__('finance.errors.amount_invalid'))],
            'method' => ['required', Rule::in(Payment::METHODS)],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => [$this->mode === 'refund' ? 'required' : 'nullable', 'string', 'max:300'],
        ], [], ['amount' => __('finance.amount'), 'method' => __('finance.method'), 'note' => $this->mode === 'refund' ? __('finance.refund_reason') : __('finance.note')]);

        try {
            $user = auth()->user();
            $cents = Money::parse($this->amount);
            $key = hash('sha256', $this->token.'|'.$this->payableType.$this->payableId);
            $this->mode === 'refund'
                ? $ledger->recordRefund($this->payable(), $cents, $this->method, $user, $this->note, $key)
                : $ledger->recordPayment($this->payable(), $cents, $this->method, $user, $this->reference ?: null, $this->note ?: null, $key);
        } catch (FinanceException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }
        $this->mode = null;
        $this->notice = __('finance.saved');
        $this->dispatch('payments-changed');
    }

    public function startVoid(int $id): void
    {
        $this->authorizeLocation('payments.refund');
        $this->voiding = Payment::where('payable_type', $this->payableType)->where('payable_id', $this->payableId)->findOrFail($id)->id;
        $this->voidReason = '';
    }

    public function cancelVoid(): void
    {
        $this->voiding = null;
        $this->voidReason = '';
    }

    public function void(Ledger $ledger): void
    {
        $this->authorizeLocation('payments.refund');
        $payment = Payment::where('payable_type', $this->payableType)->where('payable_id', $this->payableId)->findOrFail($this->voiding);
        try {
            $ledger->void($payment, $this->voidReason, auth()->user());
        } catch (FinanceException $e) {
            $this->addError('voidReason', $e->getMessage());

            return;
        }
        $this->voiding = null;
        $this->notice = __('finance.saved');
        $this->dispatch('payments-changed');
    }

    public function render(Ledger $ledger)
    {
        $payable = $this->payable();

        return view('livewire.crm.finance.payments-panel', [
            'summary' => $ledger->summary($payable),
            'payments' => Payment::with(['operator', 'voider'])->where('payable_type', $this->payableType)->where('payable_id', $this->payableId)->orderBy('received_at')->get(),
            'tz' => config('optica.clinic_timezone'),
        ]);
    }
}
