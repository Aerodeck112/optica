<?php

namespace App\Livewire\Crm;

use App\Domain\Clinical\ClinicalAccess;
use App\Domain\Patients\PatientRegistry;
use App\Models\Appointment;
use App\Models\ClinicalRecord;
use App\Models\Consent;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\PatientRelation;
use App\Models\Prescription;
use App\Models\Visit;
use App\Support\Audit\AuditLogger;
use App\Support\Consent\ConsentRecorder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Patient file (PAC-01, PAC-03). Each tab is shown only with its permission and every action is
 * re-authorised on the server. Opening a file is journaled (ACC-02).
 */
class PatientShow extends Component
{
    public const TABS = ['overview', 'appointments', 'records', 'prescriptions', 'documents', 'consents', 'tasks', 'history'];

    #[Locked]
    public int $patientId;

    #[Url]
    public string $tab = 'overview';

    public bool $editing = false;

    public array $form = [];

    public string $cnp = '';

    public bool $cnpVisible = false;

    public string $relationQuery = '';

    public string $relationType = 'parent';

    public string $guardianQuery = '';

    public ?string $notice = null;

    public function boot(): void
    {
        $this->authorize('patients.view');
    }

    public function mount(Patient $patient): void
    {
        // A merged duplicate leads to the file that was kept.
        if ($patient->merged_into_id) {
            $this->redirectRoute('crm.patients.show', $patient->merged_into_id, navigate: true);

            return;
        }
        $this->patientId = $patient->id;
        if (! in_array($this->tab, $this->tabs(), true)) {
            $this->tab = 'overview';
        }
        app(AuditLogger::class)->log('patients.viewed', $patient);
    }

    public function patient(): Patient
    {
        return Patient::findOrFail($this->patientId);
    }

    /** @return list<string> tabs this user may open */
    public function tabs(): array
    {
        $user = auth()->user();
        $patient = $this->patient();

        return array_values(array_filter(self::TABS, fn ($tab) => match ($tab) {
            'appointments' => $user->can('appointments.view'),
            'records' => $user->can('medical_records.create') || app(ClinicalAccess::class)->canSeeAnyRecords($user, $patient),
            'prescriptions' => $user->can('prescriptions.view') || $user->can('prescriptions.create'),
            'documents' => $user->can('documents.view'),
            'tasks' => $user->can('tasks.manage'),
            default => true,
        }));
    }

    public function updatedTab(): void
    {
        if (! in_array($this->tab, $this->tabs(), true)) {
            $this->tab = 'overview';
        }
    }

    // ----- personal data ----------------------------------------------------------------------

    public function edit(): void
    {
        $this->authorize('patients.update');
        $p = $this->patient();
        $this->form = [
            'last_name' => $p->last_name, 'first_name' => $p->first_name,
            'birth_date' => $p->birth_date?->toDateString() ?? '', 'sex' => $p->sex ?? '',
            'phone' => $p->phone ?? '', 'email' => $p->email ?? '', 'locale' => $p->locale,
            'address' => $p->address ?? '', 'city' => $p->city ?? '',
            'primary_location_id' => $p->primary_location_id, 'legal_guardian_id' => $p->legal_guardian_id,
            'admin_notes' => $p->admin_notes ?? '',
        ];
        $this->cnp = '';
        $this->editing = true;
    }

    public function save(): void
    {
        $this->authorize('patients.update');
        $patient = $this->patient();
        $data = $this->validate([
            'form.last_name' => ['required', 'string', 'max:100'],
            'form.first_name' => ['required', 'string', 'max:100'],
            'form.birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
            'form.sex' => ['nullable', Rule::in(['female', 'male', 'other'])],
            'form.phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9 +().-]{6,}$/'],
            'form.email' => ['nullable', 'email', 'max:190'],
            'form.locale' => ['required', Rule::in(config('optica.locales'))],
            'form.address' => ['nullable', 'string', 'max:255'],
            'form.city' => ['nullable', 'string', 'max:120'],
            'form.primary_location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'form.legal_guardian_id' => ['nullable', 'integer', Rule::exists('patients', 'id')->whereNull('merged_into_id'), Rule::notIn([$patient->id])],
            'form.admin_notes' => ['nullable', 'string', 'max:3000'],
            'cnp' => ['nullable', 'digits:13'],
        ], [], $this->attributeNames())['form'];

        $patient->fill(array_map(fn ($v) => $v === '' ? null : $v, $data));
        $patient->locale = $data['locale'];
        // The CNP is collected only when needed and only by people allowed to see it (PAC-01).
        if ($this->cnp !== '') {
            abort_unless(Gate::allows('patients.view_sensitive'), 403);
            $patient->setCnp($this->cnp);
        }
        $changed = array_keys($patient->getDirty());
        $patient->save();
        app(AuditLogger::class)->log('patients.updated', $patient, ['fields' => array_values(array_diff($changed, ['cnp_encrypted', 'cnp_hash'])), 'cnp_changed' => in_array('cnp_hash', $changed, true)]);
        $this->editing = false;
        $this->cnp = '';
        $this->notice = __('patients.saved');
    }

    private function attributeNames(): array
    {
        $names = [];
        foreach (['last_name', 'first_name', 'birth_date', 'sex', 'locale', 'address', 'city', 'primary_location_id', 'legal_guardian_id', 'admin_notes'] as $f) {
            $names['form.'.$f] = __('patients.fields.'.$f);
        }

        return $names + ['form.phone' => __('crm.fields.phone'), 'form.email' => __('crm.fields.email'), 'cnp' => 'CNP'];
    }

    public function chooseGuardian(int $id): void
    {
        abort_if($id === $this->patientId, 422);
        $this->form['legal_guardian_id'] = Patient::whereNull('merged_into_id')->findOrFail($id)->id;
        $this->guardianQuery = '';
    }

    public function revealCnp(): void
    {
        $this->authorize('patients.view_sensitive');
        $this->cnpVisible = true;
        app(AuditLogger::class)->log('patients.cnp_viewed', $this->patient());
    }

    public function archive(): void
    {
        $this->authorize('patients.archive');
        $patient = $this->patient();
        $patient->forceFill(['archived_at' => $patient->archived_at ? null : now()])->save();
        app(AuditLogger::class)->log($patient->archived_at ? 'patients.archived' : 'patients.unarchived', $patient);
    }

    // ----- family -----------------------------------------------------------------------------

    public function addRelation(int $relatedId): void
    {
        $this->authorize('patients.update');
        abort_if($relatedId === $this->patientId, 422);
        abort_unless(in_array($this->relationType, PatientRelation::RELATIONS, true), 422);
        $related = Patient::whereNull('merged_into_id')->findOrFail($relatedId);
        PatientRelation::firstOrCreate(['patient_id' => $this->patientId, 'related_patient_id' => $related->id], ['relation' => $this->relationType]);
        $this->relationQuery = '';
        app(AuditLogger::class)->log('patients.relation_added', $this->patient(), ['related' => $related->code]);
    }

    public function removeRelation(int $id): void
    {
        $this->authorize('patients.update');
        PatientRelation::where('patient_id', $this->patientId)->whereKey($id)->delete();
    }

    // ----- consents ---------------------------------------------------------------------------

    public function setMarketing(string $channel, bool $granted, ConsentRecorder $consents): void
    {
        $this->authorize('patients.update');
        $patient = $this->patient();
        $consents->recordMarketingChoice($patient->id, $patient->phone, $patient->email, $channel, $granted, 'reception', $patient->locale);
        $this->notice = __('patients.consents.saved');
    }

    public function render(PatientRegistry $registry, ConsentRecorder $consents)
    {
        $patient = $this->patient()->load(['primaryLocation', 'legalGuardian', 'relations.relatedPatient']);
        $user = auth()->user();
        $tz = config('optica.clinic_timezone');

        $data = ['patient' => $patient, 'tabs' => $this->tabs(), 'tz' => $tz];
        if ($this->tab === 'overview') {
            $data['locations'] = \App\Models\Location::where('is_active', true)->orderBy('sort_order')->get();
            $data['relationResults'] = $registry->search($this->relationQuery, 6)->where('id', '!=', $patient->id);
            $data['guardianResults'] = $this->editing ? $registry->search($this->guardianQuery, 6)->where('id', '!=', $patient->id) : collect();
            $data['guardianChoice'] = $this->editing && ($this->form['legal_guardian_id'] ?? null) ? Patient::find($this->form['legal_guardian_id']) : null;
            $data['relatedTo'] = PatientRelation::with('patient')->where('related_patient_id', $patient->id)->get();
            $data['dependants'] = Patient::where('legal_guardian_id', $patient->id)->whereNull('merged_into_id')->get();
        }
        if ($this->tab === 'appointments') {
            $data['appointments'] = Appointment::with(['service', 'specialist', 'location'])->where('patient_id', $patient->id)->orderByDesc('starts_at')->limit(200)->get();
            $data['visits'] = Visit::with(['specialist', 'location', 'service'])->where('patient_id', $patient->id)->orderByDesc('started_at')->limit(200)->get();
        }
        if ($this->tab === 'consents') {
            $data['consents'] = Consent::where('patient_id', $patient->id)->orderByDesc('given_at')->orderByDesc('id')->get();
            $data['marketing'] = collect(Consent::CHANNELS)->mapWithKeys(fn ($c) => [$c => $consents->allowsMarketing($patient->id, $patient->phone, $patient->email, $c)]);
        }
        if ($this->tab === 'history') {
            $data['timeline'] = $this->timeline($patient);
        }

        return view('livewire.crm.patient-show', $data)
            ->layout('components.layouts.crm')->title($patient->fullName());
    }

    /** Chronological history (PAC-03) of what this user is allowed to see. */
    private function timeline(Patient $patient)
    {
        $user = auth()->user();
        $items = collect();
        if ($user->can('appointments.view')) {
            foreach (Appointment::with(['service', 'location'])->where('patient_id', $patient->id)->latest('starts_at')->limit(100)->get() as $a) {
                $items->push(['at' => $a->starts_at, 'type' => 'appointment', 'title' => ($a->service?->name() ?? '—').' · '.($a->location?->name() ?? ''), 'meta' => $a->status->label()]);
            }
        }
        foreach (app(ClinicalAccess::class)->scopeRecords(ClinicalRecord::query(), $user, $patient)->with('specialist')->latest('examined_at')->limit(100)->get() as $r) {
            $items->push(['at' => $r->examined_at, 'type' => 'record', 'title' => __('patients.timeline.record', ['name' => $r->specialist?->display_name]), 'meta' => __('clinical.status.'.$r->status)]);
        }
        if ($user->can('prescriptions.view')) {
            foreach (Prescription::where('patient_id', $patient->id)->latest('issued_on')->limit(100)->get() as $rx) {
                if (app(ClinicalAccess::class)->canViewPrescription($user, $rx)) {
                    $items->push(['at' => $rx->created_at, 'type' => 'prescription', 'title' => __('clinical.rx.types.'.$rx->type), 'meta' => __('clinical.rx.version', ['number' => $rx->version])]);
                }
            }
        }
        if ($user->can('documents.view')) {
            foreach (PatientDocument::where('patient_id', $patient->id)->latest()->limit(100)->get() as $d) {
                $items->push(['at' => $d->created_at, 'type' => 'document', 'title' => $d->title, 'meta' => __('patients.documents.categories.'.$d->category)]);
            }
        }
        foreach ($patient->leads()->latest()->limit(50)->get() as $l) {
            $items->push(['at' => $l->created_at, 'type' => 'lead', 'title' => __('leads.types.'.$l->type), 'meta' => __('leads.statuses.'.$l->status)]);
        }
        foreach (Consent::where('patient_id', $patient->id)->latest('given_at')->limit(100)->get() as $c) {
            $items->push(['at' => $c->given_at, 'type' => 'consent', 'title' => __('patients.consents.purposes.'.$c->purpose).($c->channel ? ' · '.__('patients.consents.channels.'.$c->channel) : ''), 'meta' => $c->granted ? __('patients.consents.granted') : __('patients.consents.refused')]);
        }

        return $items->sortByDesc(fn ($i) => $i['at']?->getTimestamp() ?? 0)->values();
    }
}
