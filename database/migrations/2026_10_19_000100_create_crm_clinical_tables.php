<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lead history: status changes, notes, assignment, conversion (CRM-01, CRM-02).
        Schema::create('lead_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // created | status | note | assigned | converted
            $table->string('type', 20);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at');
            $table->index(['lead_id', 'created_at']);
        });

        // Internal tasks linked to a lead, patient or appointment (CRM-02, PAC-03).
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->dateTime('due_at')->nullable();
            // open | done | cancelled
            $table->string('status', 20)->default('open');
            $table->boolean('is_urgent')->default(false);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_at']);
            $table->index(['assigned_to', 'status']);
        });

        // Clinical form templates; usable only after validation by medical staff (MED-01).
        Schema::create('clinical_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60);
            $table->unsignedInteger('version')->default(1);
            $table->json('name');
            $table->json('fields');
            // draft | validated | retired
            $table->string('status', 20)->default('draft');
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['key', 'version']);
        });

        // Ophthalmology records: draft → final; corrections only through amendments (MED-03).
        Schema::create('clinical_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->constrained();
            $table->foreignId('specialist_id')->constrained();
            $table->foreignId('template_id')->constrained('clinical_templates');
            // Clinical content is encrypted at rest.
            $table->longText('data');
            // draft | final
            $table->string('status', 10)->default('draft');
            $table->unsignedInteger('lock_version')->default(1);
            $table->dateTime('examined_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'examined_at']);
            $table->index(['specialist_id', 'status']);
        });

        Schema::create('clinical_record_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinical_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 500);
            // Encrypted: field => [old, new].
            $table->longText('changes');
            $table->timestamp('created_at');
        });

        // Prescriptions have an author, a date and a version; a correction is a new version (MED-02).
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('clinical_record_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->constrained();
            $table->foreignId('specialist_id')->constrained();
            // glasses | contact_lenses
            $table->string('type', 20);
            $table->longText('data');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('supersedes_id')->nullable()->constrained('prescriptions')->nullOnDelete();
            $table->string('amend_reason', 500)->nullable();
            // active | superseded | cancelled
            $table->string('status', 20)->default('active');
            $table->date('issued_on');
            $table->date('valid_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['patient_id', 'issued_on']);
        });

        // Private files: never under public/, downloaded only through an authorised controller (MED-05).
        Schema::create('patient_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('clinical_record_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            // investigation | imaging | external_report | consent | identity | other
            $table->string('category', 30);
            $table->string('title', 200);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['patient_id', 'category']);
        });

        // Controlled merge of duplicates, kept as a journal (PAC-02).
        Schema::create('patient_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('patients');
            $table->foreignId('target_id')->constrained('patients');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('moved');
            // Encrypted copy of the duplicate's personal data.
            $table->longText('source_snapshot');
            $table->timestamp('created_at');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dateTime('closed_at')->nullable()->after('appointment_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $t) => $t->dropColumn('closed_at'));
        foreach (['patient_merges', 'patient_documents', 'prescriptions', 'clinical_record_amendments', 'clinical_records', 'clinical_templates', 'tasks', 'lead_events'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
