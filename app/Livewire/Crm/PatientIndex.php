<?php

namespace App\Livewire\Crm;

use App\Domain\Patients\PatientRegistry;
use App\Models\Patient;
use App\Support\Audit\AuditLogger;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Patient register (PAC-01): search by name, phone in any format, code or email. New patients
 * are checked against possible duplicates first; nothing is merged automatically (PAC-02).
 */
class PatientIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public bool $archived = false;

    public bool $creating = false;

    public array $form = ['last_name' => '', 'first_name' => '', 'birth_date' => '', 'phone' => '', 'email' => '', 'locale' => 'ro'];

    public bool $duplicatesSeen = false;

    public function boot(): void
    {
        $this->authorize('patients.view');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedForm(): void
    {
        $this->duplicatesSeen = false;
    }

    public function startCreate(): void
    {
        $this->authorize('patients.create');
        $this->creating = true;
        $this->duplicatesSeen = false;
        $this->form['locale'] = app()->getLocale();
    }

    public function create(PatientRegistry $registry): void
    {
        $this->authorize('patients.create');
        $data = $this->validate([
            'form.last_name' => ['required', 'string', 'max:100'],
            'form.first_name' => ['required', 'string', 'max:100'],
            'form.birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1900-01-01'],
            'form.phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9 +().-]{6,}$/'],
            'form.email' => ['nullable', 'email', 'max:190'],
            'form.locale' => ['required', 'in:'.implode(',', config('optica.locales'))],
        ], [], [
            'form.last_name' => __('patients.fields.last_name'),
            'form.first_name' => __('patients.fields.first_name'),
            'form.birth_date' => __('patients.fields.birth_date'),
            'form.phone' => __('crm.fields.phone'),
            'form.email' => __('crm.fields.email'),
        ])['form'];

        // Possible duplicates are shown once; saving again confirms it is a different person.
        if (! $this->duplicatesSeen && $registry->possibleDuplicates($data)->isNotEmpty()) {
            $this->duplicatesSeen = true;

            return;
        }

        $patient = $registry->create(array_map(fn ($v) => $v === '' ? null : $v, $data) + ['locale' => $data['locale']], auth()->user(), auth()->user()->locations()->value('locations.id'));
        app(AuditLogger::class)->log('patients.created', $patient);
        $this->redirectRoute('crm.patients.show', $patient, navigate: true);
    }

    public function render(PatientRegistry $registry)
    {
        $patients = $this->search !== '' && mb_strlen(trim($this->search)) >= 2
            ? null
            : Patient::query()->whereNull('merged_into_id')
                ->when($this->archived, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
                ->latest('id')->paginate(25);

        return view('livewire.crm.patient-index', [
            'patients' => $patients,
            'results' => $patients === null ? $registry->search($this->search, 50) : collect(),
            'duplicates' => $this->creating && $this->duplicatesSeen ? $registry->possibleDuplicates($this->form) : collect(),
        ])->layout('components.layouts.crm')->title(__('patients.title'));
    }
}
