<?php

namespace App\Domain\Finance;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\ServiceCharge;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Value of a service performed (FIN-01), linked to the appointment when there is one. */
class ChargeService
{
    public function __construct(private readonly AuditLogger $audit, private readonly Ledger $ledger) {}

    public function record(Patient $patient, int $locationId, string $description, int $amountCents, string $performedOn, User $user, ?Appointment $appointment = null): ServiceCharge
    {
        if ($amountCents < 0) {
            throw new FinanceException(__('finance.errors.amount_positive'));
        }
        if ($appointment && $appointment->patient_id !== $patient->id) {
            throw new FinanceException(__('finance.errors.appointment_mismatch'));
        }
        $charge = ServiceCharge::create([
            'patient_id' => $patient->id,
            'location_id' => $appointment?->location_id ?? $locationId,
            'appointment_id' => $appointment?->id,
            'service_id' => $appointment?->service_id,
            'specialist_id' => $appointment?->specialist_id,
            'description' => mb_substr(trim($description), 0, 300),
            'amount_cents' => $amountCents,
            'performed_on' => $performedOn,
            'created_by' => $user->id,
        ]);
        $this->audit->log('payments.charge_recorded', $charge, ['amount_cents' => $amountCents], $user, $charge->location_id);

        return $charge;
    }

    public function void(ServiceCharge $charge, string $reason, User $user): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new FinanceException(__('finance.errors.reason_required'));
        }
        DB::transaction(function () use ($charge, $reason, $user) {
            $locked = ServiceCharge::whereKey($charge->id)->lockForUpdate()->firstOrFail();
            if ($locked->voided_at) {
                throw new FinanceException(__('finance.errors.already_voided'));
            }
            // Money received for it must be refunded first, so nothing disappears from the books.
            if ($this->ledger->paidCents($locked) !== 0) {
                throw new FinanceException(__('finance.errors.refund_first'));
            }
            $locked->forceFill(['voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => mb_substr(trim($reason), 0, 300)])->save();
            $this->audit->log('payments.charge_voided', $locked, [], $user, $locked->location_id);
        });
    }
}
