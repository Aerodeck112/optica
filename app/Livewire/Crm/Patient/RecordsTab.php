<?php

namespace App\Livewire\Crm\Patient;

use App\Domain\Clinical\ClinicalAccess;
use App\Domain\Clinical\ClinicalException;
use App\Domain\Clinical\RecordService;
use App\Models\Appointment;
use App\Models\ClinicalRecord;
use App\Models\ClinicalTemplate;
use App\Models\Patient;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Records of one patient that the user may see (ACC-01), and starting a new one. */
class RecordsTab extends Component
{
    #[Locked]
    public int $patientId;

    public ?int $templateId = null;

    public ?int $appointmentId = null;

    public ?int $locationId = null;

    public function boot(): void
    {
        $this->authorize('patients.view');
    }

    public function start(RecordService $records): void
    {
        $this->authorize('medical_records.create');
        $patient = Patient::findOrFail($this->patientId);
        $template = ClinicalTemplate::where('status', 'validated')->findOrFail($this->templateId);
        $appointment = $this->appointmentId ? Appointment::where('patient_id', $patient->id)->findOrFail($this->appointmentId) : null;
        $location = $appointment?->location_id ?? $this->locationId;
        if (! $location || ! auth()->user()->can('medical_records.create', $location)) {
            $this->addError('locationId', __('patients.records.choose_location'));

            return;
        }
        try {
            $record = $records->createDraft($patient, $template, auth()->user(), $location, $appointment);
        } catch (ClinicalException $e) {
            $this->addError('templateId', $e->getMessage());

            return;
        }
        $this->redirectRoute('crm.records.edit', $record, navigate: true);
    }

    public function render(ClinicalAccess $access)
    {
        $user = auth()->user();
        $patient = Patient::findOrFail($this->patientId);
        $specialistId = $access->specialistId($user);
        $canCreate = $user->can('medical_records.create') && $specialistId;
        $templates = $canCreate ? ClinicalTemplate::where('status', 'validated')->orderBy('id')->get() : collect();
        $this->templateId ??= $templates->first()?->id;

        return view('livewire.crm.patient.records-tab', [
            'records' => $access->scopeRecords(ClinicalRecord::query(), $user, $patient)->with(['specialist', 'location', 'template'])->latest('examined_at')->get(),
            'canCreate' => $canCreate,
            'hasProfile' => (bool) $specialistId,
            'templates' => $templates,
            'appointments' => $canCreate ? Appointment::with(['service', 'location'])->where('patient_id', $patient->id)->where('specialist_id', $specialistId)
                ->where('starts_at', '>=', now()->subDays(30))->where('starts_at', '<=', now()->addDay())->orderByDesc('starts_at')->get() : collect(),
            'locations' => $canCreate ? \App\Models\Location::whereIn('id', $user->all_locations ? \App\Models\Location::pluck('id') : $user->locations()->pluck('locations.id'))->orderBy('sort_order')->get() : collect(),
            'tz' => config('optica.clinic_timezone'),
        ]);
    }
}
