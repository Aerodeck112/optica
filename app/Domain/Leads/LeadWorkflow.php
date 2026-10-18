<?php

namespace App\Domain\Leads;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Patient;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Lead handling (CRM-01, CRM-02): status, notes, assignment and conversion, each kept in the
 * lead's history. Conversion links to an existing patient chosen by staff or creates one; it
 * never merges people automatically.
 */
class LeadWorkflow
{
    public function changeStatus(Lead $lead, string $status, User $user, ?string $note = null): void
    {
        abort_unless(in_array($status, Lead::STATUSES, true), 422);
        if ($lead->status === $status) {
            return;
        }
        DB::transaction(function () use ($lead, $status, $user, $note) {
            $from = $lead->status;
            $lead->forceFill(['status' => $status, 'closed_at' => in_array($status, ['converted', 'closed'], true) ? now() : null])->save();
            $this->event($lead, $user, 'status', ['from_status' => $from, 'to_status' => $status, 'note' => $note ? trim($note) : null]);
        });
    }

    public function addNote(Lead $lead, string $note, User $user): void
    {
        $this->event($lead, $user, 'note', ['note' => trim($note)]);
        if ($lead->status === 'new') {
            $this->changeStatus($lead, 'contacted', $user);
        }
    }

    public function assign(Lead $lead, ?User $assignee, ?string $dueAt, User $user): void
    {
        $lead->forceFill(['assigned_to' => $assignee?->id, 'due_at' => $dueAt ?: null])->save();
        $this->event($lead, $user, 'assigned', ['note' => $assignee?->name]);
    }

    /** Links the lead to a patient (existing or just created) and marks it converted. */
    public function convert(Lead $lead, Patient $patient, User $user, ?int $appointmentId = null): void
    {
        DB::transaction(function () use ($lead, $patient, $user, $appointmentId) {
            $lead->forceFill(['patient_id' => $patient->id, 'appointment_id' => $appointmentId ?? $lead->appointment_id])->save();
            $this->event($lead, $user, 'converted', ['note' => $patient->code]);
            $this->changeStatus($lead, 'converted', $user);
            // Consents given in the form follow the person into the patient file.
            DB::table('consents')->where('lead_id', $lead->id)->whereNull('patient_id')->update(['patient_id' => $patient->id]);
        });
        app(AuditLogger::class)->log('leads.converted', $lead, ['patient_id' => $patient->id], $user);
    }

    public function recordCreated(Lead $lead): void
    {
        LeadEvent::create(['lead_id' => $lead->id, 'type' => 'created', 'to_status' => $lead->status, 'created_at' => $lead->created_at ?? now()]);
    }

    private function event(Lead $lead, User $user, string $type, array $values = []): void
    {
        LeadEvent::create($values + ['lead_id' => $lead->id, 'user_id' => $user->id, 'type' => $type, 'created_at' => now()]);
    }
}
