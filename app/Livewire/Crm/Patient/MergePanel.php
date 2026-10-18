<?php

namespace App\Livewire\Crm\Patient;

use App\Domain\Patients\PatientMerger;
use App\Domain\Patients\PatientRegistry;
use App\Models\Patient;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Merging a duplicate into this file (PAC-02): staff choose the duplicate, compare both files
 * side by side and confirm. The current file is the one that is kept.
 */
class MergePanel extends Component
{
    #[Locked]
    public int $patientId;

    public string $query = '';

    #[Locked]
    public ?int $duplicateId = null;

    public bool $confirmed = false;

    public function boot(): void
    {
        $this->authorize('patients.merge');
    }

    public function choose(int $id): void
    {
        abort_if($id === $this->patientId, 422);
        $this->duplicateId = Patient::whereNull('merged_into_id')->findOrFail($id)->id;
        $this->confirmed = false;
    }

    public function cancel(): void
    {
        $this->duplicateId = null;
        $this->confirmed = false;
    }

    public function merge(PatientMerger $merger): void
    {
        $this->validate(['confirmed' => ['accepted']], [], ['confirmed' => __('patients.merge.confirm_label')]);
        $merger->merge(Patient::findOrFail($this->duplicateId), Patient::findOrFail($this->patientId), auth()->user());
        session()->flash('status', __('patients.merge.done'));
        $this->redirectRoute('crm.patients.show', $this->patientId, navigate: true);
    }

    public function render(PatientRegistry $registry)
    {
        $patient = Patient::findOrFail($this->patientId);

        return view('livewire.crm.patient.merge-panel', [
            'patient' => $patient,
            'suggestions' => $registry->possibleDuplicates($patient->only(['first_name', 'last_name', 'phone']))->where('id', '!=', $patient->id),
            'results' => $registry->search($this->query, 6)->where('id', '!=', $patient->id),
            'duplicate' => $this->duplicateId ? Patient::withCount(['appointments', 'documents'])->find($this->duplicateId) : null,
            'current' => Patient::withCount(['appointments', 'documents'])->find($patient->id),
        ]);
    }
}
