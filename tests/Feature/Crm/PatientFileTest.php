<?php

namespace Tests\Feature\Crm;

use App\Livewire\Admin\ClinicalTemplates;
use App\Livewire\Crm\ClinicalRecordEditor;
use App\Livewire\Crm\LeadShow;
use App\Livewire\Crm\Patient\DocumentsTab;
use App\Livewire\Crm\Patient\MergePanel;
use App\Livewire\Crm\Patient\PrescriptionsTab;
use App\Livewire\Crm\Patient\RecordsTab;
use App\Livewire\Crm\PatientIndex;
use App\Livewire\Crm\PatientShow;
use App\Livewire\Crm\TaskList;
use App\Models\AuditLog;
use App\Models\ClinicalRecord;
use App\Models\ClinicalTemplate;
use App\Models\Lead;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\Prescription;
use App\Models\Task;
use Database\Seeders\ClinicalTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\ClinicalFixtures;
use Tests\TestCase;

class PatientFileTest extends TestCase
{
    use ClinicalFixtures, RefreshDatabase;

    public function test_patient_register_warns_about_duplicates_before_creating(): void
    {
        $this->signIn($this->staff('reception'));
        $this->makePatient(['last_name' => 'Popescu', 'first_name' => 'Ana', 'phone' => '0744 111 222']);

        $component = Livewire::test(PatientIndex::class)->call('startCreate')
            ->set('form.last_name', 'Popescu')->set('form.first_name', 'Maria')->set('form.phone', '+40744111222')
            ->call('create')->assertSet('duplicatesSeen', true)->assertSee('Popescu Ana');
        $this->assertSame(1, Patient::count());

        $component->call('create')->assertRedirect();
        $this->assertSame(2, Patient::count());
        $this->assertSame('P'.str_pad((string) Patient::max('id'), 6, '0', STR_PAD_LEFT), Patient::latest('id')->value('code'));
    }

    public function test_opening_a_file_is_journaled_and_clinical_tabs_follow_rights(): void
    {
        $location = $this->makeLocation();
        $patient = $this->makePatient();
        $reception = $this->staff('reception', [], [$location->id]);
        $this->signIn($reception)->get(route('crm.patients.show', $patient))->assertOk()->assertDontSee(__('patients.tabs.records'));
        $this->assertTrue(AuditLog::where('action', 'patients.viewed')->where('user_id', $reception->id)->exists());

        $doctor = $this->doctor($location);
        $this->signIn($doctor)->get(route('crm.patients.show', $patient))->assertOk()->assertSee(__('patients.tabs.records'));

        $this->signIn($this->staff('marketing'))->get(route('crm.patients.show', $patient))->assertForbidden();
    }

    public function test_cnp_is_hidden_until_revealed_and_needs_the_sensitive_right(): void
    {
        $patient = $this->makePatient();
        $patient->setCnp('1900101123456');
        $patient->save();

        $this->signIn($this->staff('assistant'));
        Livewire::test(PatientShow::class, ['patient' => $patient])->assertDontSee('1900101123456')->call('revealCnp')->assertForbidden();

        $user = $this->staff('reception');
        $this->signIn($user);
        Livewire::test(PatientShow::class, ['patient' => $patient])->assertDontSee('1900101123456')
            ->call('revealCnp')->assertSee('1900101123456');
        $this->assertTrue(AuditLog::where('action', 'patients.cnp_viewed')->where('user_id', $user->id)->exists());
    }

    public function test_a_doctor_writes_finalizes_and_amends_a_record_through_the_screens(): void
    {
        $template = $this->validatedTemplate();
        $location = $this->makeLocation();
        $patient = $this->makePatient();
        $doctor = $this->doctor($location);
        $this->signIn($doctor);

        Livewire::test(RecordsTab::class, ['patientId' => $patient->id])
            ->set('templateId', $template->id)->set('locationId', $location->id)->call('start')->assertRedirect();
        $record = ClinicalRecord::firstOrFail();

        $editor = Livewire::test(ClinicalRecordEditor::class, ['record' => $record])
            ->set('data.reason', 'Control DEMO')->set('data.sph.od', '-1,25')->call('save')->assertSet('failure', null)
            ->call('finalize')->assertHasErrors(['data.diagnosis'])
            ->set('data.diagnosis', 'Text DEMO')->call('finalize')->assertSet('failure', null);
        $this->assertTrue($record->fresh()->isFinal());

        $editor->call('startAmend')->set('data.sph.od', '-1.5')->set('reason', 'Transcriere greșită DEMO')->call('amend')->assertSet('failure', null);
        $this->assertSame('-1.5', $record->fresh()->data['sph']['od']);

        $this->get(route('crm.records.pdf', ['record' => $record, 'lang' => 'hu']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertTrue(AuditLog::where('action', 'medical_records.exported_pdf')->exists());
    }

    public function test_records_and_their_pdf_are_refused_without_clinical_rights(): void
    {
        $template = $this->validatedTemplate();
        $location = $this->makeLocation();
        $doctor = $this->doctor($location);
        $record = app(\App\Domain\Clinical\RecordService::class)->createDraft($this->makePatient(), $template, $doctor, $location->id);

        foreach (['superadmin', 'reception', 'location_manager'] as $role) {
            $this->signIn($this->staff($role, [], [$location->id]));
            $this->get(route('crm.records.edit', $record))->assertForbidden();
            $this->get(route('crm.records.pdf', $record))->assertForbidden();
        }
        // Another doctor without a relation to the patient sees nothing either.
        $this->signIn($this->doctor($location));
        $this->get(route('crm.records.edit', $record))->assertForbidden();
        Livewire::test(RecordsTab::class, ['patientId' => $record->patient_id])->assertSee(__('patients.records.none'));
    }

    public function test_prescriptions_through_the_screen_with_pdf(): void
    {
        $location = $this->makeLocation();
        $patient = $this->makePatient();
        $this->signIn($this->doctor($location));

        Livewire::test(PrescriptionsTab::class, ['patientId' => $patient->id])->call('create')
            ->set('locationId', $location->id)->set('data.od.sph', '-2')->set('data.os.sph', '-1,75')->set('data.pd', '62')
            ->call('save')->assertHasNoErrors()->assertSee('-2.00');
        $rx = Prescription::firstOrFail();
        $this->get(route('crm.prescriptions.pdf', $rx))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->signIn($this->staff('reception', [], [$location->id]));
        $this->get(route('crm.prescriptions.pdf', $rx))->assertForbidden();
    }

    public function test_documents_are_uploaded_privately_and_downloaded_only_with_permission(): void
    {
        Storage::fake('local');
        $patient = $this->makePatient();
        $this->signIn($this->staff('reception'));
        Livewire::test(DocumentsTab::class, ['patientId' => $patient->id])
            ->set('file', UploadedFile::fake()->createWithContent('scrisoare.pdf', "%PDF-1.4\n%DEMO\n"))
            ->set('title', 'Scrisoare medicală DEMO')->set('category', 'external_report')
            ->call('upload')->assertHasNoErrors();
        $document = PatientDocument::firstOrFail();

        $this->get(route('crm.documents.download', $document))->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertDownload('scrisoare-medicala-demo.pdf');
        $this->assertTrue(AuditLog::where('action', 'documents.downloaded')->exists());

        $this->signIn($this->staff('marketing'));
        $this->get(route('crm.documents.download', $document))->assertForbidden();
        $this->assertFileDoesNotExist(public_path($document->path));
    }

    public function test_merge_needs_its_own_permission(): void
    {
        $keep = $this->makePatient();
        $duplicate = $this->makePatient();
        $this->signIn($this->staff('reception'));
        Livewire::test(MergePanel::class, ['patientId' => $keep->id])->assertForbidden();

        $this->signIn($this->staff('location_manager'));
        Livewire::test(MergePanel::class, ['patientId' => $keep->id])
            ->call('choose', $duplicate->id)->call('merge')->assertHasErrors(['confirmed'])
            ->set('confirmed', true)->call('merge')->assertRedirect();
        $this->assertSame($keep->id, $duplicate->fresh()->merged_into_id);
        // Opening the duplicate leads to the kept file.
        $this->get(route('crm.patients.show', $duplicate))->assertRedirect(route('crm.patients.show', $keep));
    }

    public function test_lead_screen_converts_without_duplicating_the_person(): void
    {
        $patient = $this->makePatient(['phone' => '0711 222 333']);
        $lead = Lead::create(['type' => 'booking_request', 'name' => 'Pacient DEMO', 'phone' => '0711222333', 'message' => 'Aș dori o programare']);
        $user = $this->staff('reception');
        $this->signIn($user);

        Livewire::test(LeadShow::class, ['lead' => $lead])
            ->assertSee($patient->fullName())
            ->set('note', 'Sunat, revine.')->call('addNote')
            ->set('status', 'converted')->assertSet('status', 'contacted')
            ->call('convertTo', $patient->id)->assertSet('status', 'converted');
        $this->assertSame($patient->id, $lead->fresh()->patient_id);
        $this->assertSame(1, Patient::count());

        $this->signIn($this->staff('specialist'));
        $this->get(route('crm.leads.show', $lead))->assertForbidden();
    }

    public function test_tasks_for_a_patient(): void
    {
        $patient = $this->makePatient();
        $user = $this->staff('reception');
        $this->signIn($user);
        Livewire::test(TaskList::class, ['subjectType' => 'patient', 'subjectId' => $patient->id])
            ->call('create')->set('form.title', 'Sună pentru rezultatul analizelor')->set('form.due_at', '2026-11-03T10:00')->call('save')->assertHasNoErrors()
            ->assertSee('Sună pentru rezultatul analizelor');
        $task = Task::firstOrFail();
        $this->assertSame('patient', $task->subject_type);
        $this->assertSame('2026-11-03 08:00:00', $task->due_at->utc()->format('Y-m-d H:i:s'));

        Livewire::test(TaskList::class)->assertSee('Sună pentru rezultatul analizelor')->call('setStatus', $task->id, 'done');
        $this->assertSame('done', $task->fresh()->status);
        $this->get(route('crm.tasks'))->assertOk();
    }

    public function test_templates_are_validated_only_by_medical_staff(): void
    {
        $this->seed(ClinicalTemplateSeeder::class);
        $template = ClinicalTemplate::firstOrFail();
        $this->signIn($this->staff('superadmin'));
        $this->get(route('admin.clinical-templates'))->assertForbidden();

        $chief = $this->staffWith(['medical_records.templates']);
        $this->signIn($chief);
        Livewire::test(ClinicalTemplates::class)->call('edit', $template->id)
            ->set('fields.0.label.hu', 'A megjelenés oka')->call('approve', $template->id);
        $template->refresh();
        $this->assertSame('validated', $template->status);
        $this->assertSame($chief->id, $template->validated_by);

        // A validated template is never edited in place: a change is a new version.
        Livewire::test(ClinicalTemplates::class)->call('newVersion', $template->id)->call('approve', ClinicalTemplate::where('version', 2)->value('id'));
        $this->assertSame('retired', $template->fresh()->status);
        $this->assertSame('validated', ClinicalTemplate::where('version', 2)->value('status'));
    }

    private function staffWith(array $permissions)
    {
        $user = $this->staff('reception');
        $role = \App\Models\Role::create(['key' => 'medical-chief-'.uniqid(), 'name' => ['ro' => 'Test', 'hu' => 'Test']]);
        $role->permissions()->sync(\App\Models\Permission::whereIn('key', $permissions)->pluck('id'));
        $user->roles()->sync([$role->id]);
        app(\App\Support\Access\AccessControl::class)->flush();

        return $user->fresh();
    }
}
