<?php

namespace App\Livewire\Crm\Patient;

use App\Domain\Documents\DocumentVault;
use App\Models\Patient;
use App\Models\PatientDocument;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/** Private documents and investigations (MED-05). */
class DocumentsTab extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $patientId;

    public $file;

    public string $title = '';

    public string $category = 'investigation';

    public ?string $notice = null;

    public function boot(): void
    {
        $this->authorize('documents.view');
    }

    public function uploadDocument(DocumentVault $vault): void
    {
        $this->authorize('documents.upload');
        $this->validate([
            'file' => ['required', 'file', 'max:'.DocumentVault::MAX_KB, 'mimes:pdf,jpg,jpeg,png,webp'],
            'title' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(PatientDocument::CATEGORIES)],
        ], [], ['file' => __('patients.documents.file'), 'title' => __('patients.documents.title_field'), 'category' => __('patients.documents.category')]);

        try {
            $vault->store(Patient::findOrFail($this->patientId), $this->file, $this->category, $this->title, auth()->user(), auth()->user()->locations()->value('locations.id'));
        } catch (RuntimeException) {
            $this->addError('file', __('patients.documents.rejected'));

            return;
        }
        $this->reset(['file', 'title']);
        $this->notice = __('patients.documents.uploaded');
    }

    public function delete(int $id, DocumentVault $vault): void
    {
        $this->authorize('documents.delete');
        $vault->delete(PatientDocument::where('patient_id', $this->patientId)->findOrFail($id), auth()->user());
        $this->notice = __('patients.documents.deleted');
    }

    public function render()
    {
        return view('livewire.crm.patient.documents-tab', [
            'documents' => PatientDocument::with('uploader')->where('patient_id', $this->patientId)->latest()->get(),
            'tz' => config('optica.clinic_timezone'),
        ]);
    }
}
