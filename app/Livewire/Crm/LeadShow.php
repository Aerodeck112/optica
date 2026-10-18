<?php

namespace App\Livewire\Crm;

use App\Domain\Leads\LeadWorkflow;
use App\Domain\Patients\PatientRegistry;
use App\Models\Lead;
use App\Models\Patient;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One lead: details, status, notes, assignment, tasks and conversion to a patient (CRM-02).
 * Conversion proposes existing patients first so the person is not duplicated.
 */
class LeadShow extends Component
{
    #[Locked]
    public int $leadId;

    public string $note = '';

    public string $status = '';

    public ?int $assignee = null;

    public string $dueAt = '';

    public string $patientQuery = '';

    public ?string $notice = null;

    public function boot(): void
    {
        $this->authorize('leads.view');
    }

    public function mount(Lead $lead): void
    {
        abort_unless(LeadIndex::scoped()->whereKey($lead->id)->exists(), 403);
        $this->leadId = $lead->id;
        $this->status = $lead->status;
        $this->assignee = $lead->assigned_to;
        $this->dueAt = $lead->due_at?->timezone(config('optica.clinic_timezone'))->format('Y-m-d\TH:i') ?? '';
        $this->patientQuery = $lead->phone ?? $lead->name;
        app(AuditLogger::class)->log('leads.viewed', $lead);
    }

    private function lead(): Lead
    {
        return LeadIndex::scoped()->findOrFail($this->leadId);
    }

    public function addNote(LeadWorkflow $workflow): void
    {
        $this->authorize('leads.manage');
        $this->validate(['note' => ['required', 'string', 'max:3000']], [], ['note' => __('leads.note')]);
        $workflow->addNote($this->lead(), $this->note, auth()->user());
        $this->note = '';
        $this->status = $this->lead()->status;
    }

    public function updatedStatus(LeadWorkflow $workflow): void
    {
        $this->authorize('leads.manage');
        abort_unless(in_array($this->status, Lead::STATUSES, true), 422);
        // "Converted" is set only by linking a patient.
        if ($this->status === 'converted') {
            $this->status = $this->lead()->status;

            return;
        }
        $workflow->changeStatus($this->lead(), $this->status, auth()->user());
    }

    public function saveAssignment(LeadWorkflow $workflow): void
    {
        $this->authorize('leads.manage');
        $this->validate([
            'assignee' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'dueAt' => ['nullable', 'date'],
        ]);
        $due = $this->dueAt ? \Carbon\CarbonImmutable::parse($this->dueAt, config('optica.clinic_timezone'))->utc()->toDateTimeString() : null;
        $workflow->assign($this->lead(), $this->assignee ? User::find($this->assignee) : null, $due, auth()->user());
        $this->notice = __('leads.assigned');
    }

    public function convertTo(int $patientId, LeadWorkflow $workflow): void
    {
        $this->authorize('leads.manage');
        $workflow->convert($this->lead(), Patient::whereNull('merged_into_id')->findOrFail($patientId), auth()->user());
        $this->status = 'converted';
        $this->notice = __('leads.converted');
    }

    public function convertNew(LeadWorkflow $workflow, PatientRegistry $registry): void
    {
        $this->authorize('leads.manage');
        $this->authorize('patients.create');
        $lead = $this->lead();
        // The form has a single name field; staff correct first/last name in the patient file.
        $parts = preg_split('/\s+/', trim($lead->name), 2);
        $patient = $registry->create([
            'last_name' => $parts[0],
            'first_name' => $parts[1] ?? '—',
            'phone' => $lead->phone,
            'email' => $lead->email,
            'locale' => $lead->locale,
        ], auth()->user(), $lead->location_id, 'website');
        app(AuditLogger::class)->log('patients.created', $patient, ['from_lead' => $lead->id]);
        $workflow->convert($lead, $patient, auth()->user());
        $this->redirectRoute('crm.patients.show', $patient, navigate: true);
    }

    public function render(PatientRegistry $registry)
    {
        $lead = $this->lead()->load(['events.user', 'location', 'patient', 'assignee']);

        return view('livewire.crm.lead-show', [
            'lead' => $lead,
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'candidates' => ! $lead->patient_id && auth()->user()->can('patients.view') ? $registry->search($this->patientQuery, 6) : collect(),
            'tz' => config('optica.clinic_timezone'),
        ])->layout('components.layouts.crm')->title($lead->name);
    }
}
