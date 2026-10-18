<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\ClinicalException;
use App\Domain\Clinical\PrescriptionService;
use App\Domain\Documents\DocumentVault;
use App\Domain\Leads\LeadWorkflow;
use App\Domain\Patients\PatientMerger;
use App\Models\Consent;
use App\Models\Lead;
use App\Models\Patient;
use App\Models\PatientMerge;
use App\Models\PatientRelation;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\ClinicalFixtures;
use Tests\TestCase;

class PatientDataTest extends TestCase
{
    use ClinicalFixtures, RefreshDatabase;

    public function test_prescriptions_are_versioned_with_author_and_reason(): void
    {
        $location = $this->makeLocation();
        $doctor = $this->doctor($location);
        $patient = $this->makePatient();
        $service = app(PrescriptionService::class);

        $first = $service->issue($patient, 'glasses', ['od' => ['sph' => '-2', 'cyl' => '-0,75', 'axis' => '180'], 'os' => ['sph' => '-1.75'], 'pd' => '63'], $doctor, $location->id);
        $this->assertSame(1, $first->version);
        $this->assertSame('-0.75', $first->data['od']['cyl']);

        $second = $service->amend($first, ['od' => ['sph' => '-2.25', 'cyl' => '-0.75', 'axis' => '180'], 'os' => ['sph' => '-1.75'], 'pd' => '63'], 'Corectură după reverificare (DEMO)', $doctor);
        $this->assertSame(2, $second->version);
        $this->assertSame('superseded', $first->fresh()->status);
        $this->assertSame($first->id, $second->supersedes_id);
        $this->assertSame($doctor->id, $second->created_by);

        $this->expectException(ClinicalException::class);
        $service->amend($first->fresh(), ['od' => ['sph' => '-3']], 'Încă o corectură DEMO', $doctor);
    }

    public function test_prescription_values_are_checked(): void
    {
        $location = $this->makeLocation();
        $doctor = $this->doctor($location);
        $service = app(PrescriptionService::class);

        try {
            $service->issue($this->makePatient(), 'glasses', ['od' => ['sph' => '-2.1', 'cyl' => '-1']], $doctor, $location->id);
            $this->fail('Invalid prescription accepted');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['data.od.sph', 'data.od.axis'], array_keys($e->errors()));
        }
        $this->expectException(ClinicalException::class);
        $service->issue($this->makePatient(), 'glasses', ['pd' => '62'], $doctor, $location->id);
    }

    public function test_reception_cannot_issue_prescriptions_without_a_specialist_profile(): void
    {
        $location = $this->makeLocation();
        $this->expectException(ClinicalException::class);
        app(PrescriptionService::class)->issue($this->makePatient(), 'glasses', ['od' => ['sph' => '1']], $this->staff('reception', [], [$location->id]), $location->id);
    }

    public function test_merging_moves_every_reference_and_keeps_a_journal(): void
    {
        $location = $this->makeLocation();
        $doctor = $this->doctor($location);
        $keep = $this->makePatient(['first_name' => 'Ana', 'email' => null]);
        $duplicate = $this->makePatient(['first_name' => 'Ana', 'email' => 'ana.demo@example.test', 'admin_notes' => 'Notă DEMO']);
        $child = $this->makePatient(['legal_guardian_id' => $duplicate->id]);
        PatientRelation::create(['patient_id' => $duplicate->id, 'related_patient_id' => $child->id, 'relation' => 'parent']);
        PatientRelation::create(['patient_id' => $duplicate->id, 'related_patient_id' => $keep->id, 'relation' => 'other']);
        $appointment = $this->appointmentFor($duplicate, $doctor->specialist, $location);
        $rx = app(PrescriptionService::class)->issue($duplicate, 'glasses', ['od' => ['sph' => '1']], $doctor, $location->id);
        $consent = Consent::create(['patient_id' => $duplicate->id, 'purpose' => 'privacy_notice', 'granted' => true, 'text_version' => '1', 'locale' => 'ro', 'source' => 'reception', 'given_at' => now()]);
        $task = Task::create(['title' => 'Sună pacientul', 'subject_type' => 'patient', 'subject_id' => $duplicate->id]);

        $merge = app(PatientMerger::class)->merge($duplicate, $keep, $this->staff('location_manager'));

        $this->assertSame($keep->id, $appointment->fresh()->patient_id);
        $this->assertSame($keep->id, $rx->fresh()->patient_id);
        $this->assertSame($keep->id, $consent->fresh()->patient_id);
        $this->assertSame($keep->id, $task->fresh()->subject_id);
        $this->assertSame($keep->id, $child->fresh()->legal_guardian_id);
        $this->assertTrue(PatientRelation::where('patient_id', $keep->id)->where('related_patient_id', $child->id)->exists());
        $this->assertFalse(PatientRelation::where('patient_id', $keep->id)->where('related_patient_id', $keep->id)->exists());
        $this->assertSame('ana.demo@example.test', $keep->fresh()->email);
        $this->assertStringContainsString('Notă DEMO', $keep->fresh()->admin_notes);
        $this->assertSame($keep->id, $duplicate->fresh()->merged_into_id);
        $this->assertNotNull($duplicate->fresh()->archived_at);
        $this->assertSame('ana.demo@example.test', PatientMerge::find($merge->id)->source_snapshot['email']);

        $this->expectException(\RuntimeException::class);
        app(PatientMerger::class)->merge($duplicate->fresh(), $keep->fresh(), $this->staff('location_manager'));
    }

    public function test_documents_are_private_and_checked_by_content(): void
    {
        Storage::fake('local');
        $patient = $this->makePatient();
        $user = $this->staff('reception');
        $vault = app(DocumentVault::class);

        $pdf = UploadedFile::fake()->createWithContent('raport.pdf', "%PDF-1.4\n%DEMO\n");
        $document = $vault->store($patient, $pdf, 'external_report', 'Raport extern DEMO', $user);
        Storage::disk('local')->assertExists($document->path);
        $this->assertStringNotContainsString('.pdf', $document->path);
        $this->assertSame('application/pdf', $document->mime);
        $this->assertSame('raport-extern-demo.pdf', $vault->downloadName($document));

        foreach ([UploadedFile::fake()->createWithContent('poza.jpg', '<?php system($_GET["c"]); ?>'), UploadedFile::fake()->createWithContent('fals.pdf', 'nu este pdf')] as $bad) {
            try {
                $vault->store($patient, $bad, 'other', 'Rău', $user);
                $this->fail('Unsafe file accepted');
            } catch (\RuntimeException) {
            }
        }
        $this->assertSame(1, $patient->documents()->count());
    }

    public function test_lead_conversion_links_the_patient_and_keeps_history(): void
    {
        $user = $this->staff('reception');
        $lead = Lead::create(['type' => 'contact', 'name' => 'Persoană DEMO', 'phone' => '0700000999', 'message' => 'Întrebare DEMO']);
        Consent::create(['lead_id' => $lead->id, 'purpose' => 'privacy_notice', 'granted' => true, 'text_version' => '1', 'locale' => 'ro', 'source' => 'contact_form', 'given_at' => now()]);
        $workflow = app(LeadWorkflow::class);
        $workflow->recordCreated($lead);

        $workflow->addNote($lead, 'Am sunat, revine mâine.', $user);
        $this->assertSame('contacted', $lead->fresh()->status);

        $patient = $this->makePatient();
        $workflow->convert($lead->fresh(), $patient, $user);
        $lead->refresh();
        $this->assertSame('converted', $lead->status);
        $this->assertSame($patient->id, $lead->patient_id);
        $this->assertNotNull($lead->closed_at);
        $this->assertSame($patient->id, Consent::where('lead_id', $lead->id)->value('patient_id'));
        $this->assertSame(['status', 'converted', 'status', 'note', 'created'], $lead->events()->pluck('type')->all());
        $this->assertSame(1, Patient::count());
    }
}
