<?php

namespace App\Livewire\Crm;

use App\Domain\Clinical\ClinicalAccess;
use App\Domain\Clinical\ClinicalException;
use App\Domain\Clinical\RecordService;
use App\Domain\Clinical\TemplateSchema;
use App\Models\ClinicalRecord;
use App\Support\Audit\AuditLogger;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Ophthalmology record form (MED-01…03). The specialist fills a draft, finalizes it, and later
 * corrects it only through a tracked amendment. Viewing is journaled.
 */
class ClinicalRecordEditor extends Component
{
    #[Locked]
    public int $recordId;

    #[Locked]
    public int $lockVersion;

    public array $data = [];

    public bool $amending = false;

    public string $reason = '';

    public ?string $notice = null;

    public ?string $failure = null;

    public function mount(ClinicalRecord $record, ClinicalAccess $access): void
    {
        abort_unless($access->canViewRecord(auth()->user(), $record), 403);
        $this->recordId = $record->id;
        $this->load($record);
        app(AuditLogger::class)->log('medical_records.viewed', $record, ['patient_id' => $record->patient_id], null, $record->location_id);
    }

    private function load(ClinicalRecord $record): void
    {
        $this->data = array_replace(TemplateSchema::blank($record->template), $record->data ?? []);
        $this->lockVersion = $record->lock_version;
    }

    private function record(): ClinicalRecord
    {
        $record = ClinicalRecord::with(['template', 'patient'])->findOrFail($this->recordId);
        // Re-checked on every request: rights can change while the page is open (ACC-02).
        abort_unless(app(ClinicalAccess::class)->canViewRecord(auth()->user(), $record), 403);

        return $record;
    }

    public function save(RecordService $service, ClinicalAccess $access): void
    {
        $record = $this->record();
        abort_unless($access->canEditDraft(auth()->user(), $record), 403);
        $this->run(function () use ($service, $record) {
            $record = $service->saveDraft($record, $this->data, $this->lockVersion, auth()->user());
            $this->load($record);
            $this->notice = __('patients.records.draft_saved');
        });
    }

    public function finalize(RecordService $service, ClinicalAccess $access): void
    {
        $record = $this->record();
        abort_unless($access->canFinalize(auth()->user(), $record), 403);
        $this->run(function () use ($service, $record) {
            $record = $service->finalize($record, $this->data, $this->lockVersion, auth()->user());
            $this->load($record);
            $this->notice = __('patients.records.finalized');
        });
    }

    public function startAmend(ClinicalAccess $access): void
    {
        abort_unless($access->canAmend(auth()->user(), $this->record()), 403);
        $this->amending = true;
        $this->reason = '';
    }

    public function cancelAmend(): void
    {
        $this->amending = false;
        $this->load($this->record());
        $this->resetErrorBag();
    }

    public function amend(RecordService $service, ClinicalAccess $access): void
    {
        $record = $this->record();
        abort_unless($access->canAmend(auth()->user(), $record), 403);
        $this->run(function () use ($service, $record) {
            $service->amend($record, $this->data, $this->reason, auth()->user());
            $this->load($record->refresh());
            $this->amending = false;
            $this->notice = __('patients.records.amended');
        });
    }

    public function reload(): void
    {
        $this->load($this->record());
        $this->failure = null;
    }

    private function run(callable $action): void
    {
        $this->notice = $this->failure = null;
        $this->resetErrorBag();
        try {
            $action();
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
            $this->failure = __('cms.pages.fix_errors');
        } catch (ClinicalException $e) {
            $this->failure = $e->getMessage();
        }
    }

    public function render(ClinicalAccess $access)
    {
        $record = $this->record()->load(['specialist', 'location', 'author', 'finalizer', 'amendments.user', 'appointment.service']);
        $user = auth()->user();
        $fields = TemplateSchema::fields($record->template);

        return view('livewire.crm.clinical-record-editor', [
            'record' => $record,
            'sections' => collect($fields)->groupBy(fn ($f) => $f['section'] ?? 'other'),
            'fieldsByKey' => collect($fields)->keyBy('key'),
            'editable' => ($access->canEditDraft($user, $record)) || ($this->amending && $access->canAmend($user, $record)),
            'canFinalize' => $access->canFinalize($user, $record),
            'canAmend' => $access->canAmend($user, $record),
            'tz' => config('optica.clinic_timezone'),
        ])->layout('components.layouts.crm')->title(__('patients.records.title_for', ['name' => $record->patient->fullName()]));
    }
}
