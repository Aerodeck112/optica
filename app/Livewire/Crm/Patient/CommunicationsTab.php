<?php

namespace App\Livewire\Crm\Patient;

use App\Domain\Messaging\MessageDispatcher;
use App\Models\Location;
use App\Models\Patient;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** The patient's messages and a recall at a date set by staff (COM-04). */
class CommunicationsTab extends Component
{
    #[Locked]
    public int $patientId;

    public string $recallDate = '';

    public ?int $recallLocation = null;

    public ?string $notice = null;

    public function boot(): void
    {
        $this->authorize('communications.view_log');
    }

    public function scheduleRecall(MessageDispatcher $dispatcher): void
    {
        $this->authorize('communications.send');
        $this->validate([
            'recallDate' => ['required', 'date_format:Y-m-d', 'after:today'],
            'recallLocation' => ['required', 'integer', 'exists:locations,id'],
        ], [], ['recallDate' => __('messages.patient.recall_date'), 'recallLocation' => __('crm.fields.location')]);
        $sendAt = CarbonImmutable::parse($this->recallDate.' 10:00', config('optica.clinic_timezone'))->utc();
        $message = $dispatcher->recall(Patient::findOrFail($this->patientId), Location::findOrFail($this->recallLocation), $sendAt, auth()->id());
        if (! $message) {
            $this->addError('recallDate', __('messages.patient.recall_not_queued'));

            return;
        }
        app(AuditLogger::class)->log('communications.recall_scheduled', $message, ['patient_id' => $this->patientId]);
        $this->reset(['recallDate']);
        $this->notice = $message->status === 'skipped' ? $message->status_reason : __('messages.patient.recall_saved');
    }

    public function render()
    {
        return view('livewire.crm.patient.communications-tab', [
            'locations' => Location::where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }
}
