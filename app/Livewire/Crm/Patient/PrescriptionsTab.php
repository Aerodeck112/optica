<?php

namespace App\Livewire\Crm\Patient;

use App\Domain\Clinical\ClinicalAccess;
use App\Domain\Clinical\ClinicalException;
use App\Domain\Clinical\PrescriptionService;
use App\Models\ClinicalRecord;
use App\Models\Patient;
use App\Models\Prescription;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Prescriptions written by specialists; corrections create a new version (MED-02). */
class PrescriptionsTab extends Component
{
    #[Locked]
    public int $patientId;

    /** null = closed, 0 = new, id = correcting that prescription */
    #[Locked]
    public ?int $editing = null;

    public string $type = 'glasses';

    public array $data = [];

    public string $validUntil = '';

    public string $reason = '';

    public ?int $recordId = null;

    public ?int $locationId = null;

    public ?string $notice = null;

    public function boot(): void
    {
        $this->authorize('patients.view');
    }

    public function create(): void
    {
        $this->authorize('prescriptions.create');
        $this->editing = 0;
        $this->type = 'glasses';
        $this->data = PrescriptionService::blank();
        $this->validUntil = '';
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function amend(int $id, ClinicalAccess $access): void
    {
        $this->authorize('prescriptions.amend');
        $rx = Prescription::where('patient_id', $this->patientId)->where('status', 'active')->findOrFail($id);
        abort_unless($access->canViewPrescription(auth()->user(), $rx), 403);
        $this->editing = $rx->id;
        $this->type = $rx->type;
        $this->data = array_replace_recursive(PrescriptionService::blank(), $rx->data);
        $this->validUntil = $rx->valid_until?->toDateString() ?? '';
        $this->reason = '';
        $this->resetErrorBag();
    }

    /** Copies the refraction the specialist wrote in a record; nothing is calculated. */
    public function copyFromRecord(ClinicalAccess $access): void
    {
        $record = ClinicalRecord::where('patient_id', $this->patientId)->findOrFail($this->recordId);
        abort_unless($access->canViewRecord(auth()->user(), $record), 403);
        foreach (['sph', 'cyl', 'axis', 'add'] as $field) {
            foreach (['od', 'os'] as $eye) {
                if (isset($record->data[$field][$eye])) {
                    $this->data[$eye][$field] = $record->data[$field][$eye];
                }
            }
        }
        if (isset($record->data['pd']) && is_string($record->data['pd'])) {
            $this->data['pd'] = $record->data['pd'];
        }
    }

    public function save(PrescriptionService $service, ClinicalAccess $access): void
    {
        $user = auth()->user();
        try {
            if ($this->editing) {
                $this->authorize('prescriptions.amend');
                $previous = Prescription::where('patient_id', $this->patientId)->findOrFail($this->editing);
                abort_unless($access->canViewPrescription($user, $previous), 403);
                $service->amend($previous, $this->data, $this->reason, $user, $this->validUntil ?: null);
            } else {
                $this->authorize('prescriptions.create');
                $record = $this->recordId ? ClinicalRecord::where('patient_id', $this->patientId)->find($this->recordId) : null;
                if ($record && ! $access->canViewRecord($user, $record)) {
                    abort(403);
                }
                $location = $record?->location_id ?? $this->locationId;
                if (! $location || ! $user->can('prescriptions.create', $location)) {
                    $this->addError('locationId', __('patients.records.choose_location'));

                    return;
                }
                $service->issue(Patient::findOrFail($this->patientId), $this->type, $this->data, $user, $location, $record, $this->validUntil ?: null);
            }
        } catch (ClinicalException $e) {
            $this->addError('form', $e->getMessage());

            return;
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->editing = null;
        $this->notice = __('patients.rx.saved');
    }

    public function render(ClinicalAccess $access)
    {
        $user = auth()->user();
        $patient = Patient::findOrFail($this->patientId);
        $prescriptions = Prescription::with(['specialist', 'author', 'location'])->where('patient_id', $patient->id)
            ->latest('issued_on')->latest('id')->get()->filter(fn ($rx) => $access->canViewPrescription($user, $rx))->values();
        $specialistId = $access->specialistId($user);

        return view('livewire.crm.patient.prescriptions-tab', [
            'prescriptions' => $prescriptions,
            'canCreate' => $user->can('prescriptions.create') && $specialistId,
            'records' => $this->editing === 0 ? $access->scopeRecords(ClinicalRecord::query(), $user, $patient)->latest('examined_at')->limit(20)->get() : collect(),
            'locations' => $this->editing === 0 ? \App\Models\Location::whereIn('id', $user->all_locations ? \App\Models\Location::pluck('id') : $user->locations()->pluck('locations.id'))->orderBy('sort_order')->get() : collect(),
            'fields' => PrescriptionService::TYPE_FIELDS[$this->type] ?? PrescriptionService::TYPE_FIELDS['glasses'],
            'tz' => config('optica.clinic_timezone'),
        ]);
    }
}
