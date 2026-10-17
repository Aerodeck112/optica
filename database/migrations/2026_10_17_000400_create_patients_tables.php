<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->date('birth_date')->nullable();
            $table->string('sex', 10)->nullable();
            $table->string('phone', 32)->nullable();
            // E.164 digits only, used for duplicate detection; never a unique key (PAC-02).
            $table->string('phone_normalized', 20)->nullable()->index();
            $table->string('email', 190)->nullable()->index();
            $table->string('locale', 5)->default('ro');
            // CNP is encrypted at rest; the keyed hash allows exact lookup without decrypting (PAC-01).
            $table->text('cnp_encrypted')->nullable();
            $table->string('cnp_hash', 64)->nullable()->index();
            $table->string('address', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('source', 30)->nullable();
            $table->foreignId('primary_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('legal_guardian_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->text('admin_notes')->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->string('external_ref', 120)->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['last_name', 'first_name']);
            $table->index('birth_date');
        });

        Schema::create('patient_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_patient_id')->constrained('patients')->cascadeOnDelete();
            // parent | child | spouse | sibling | guardian | other
            $table->string('relation', 20);
            $table->timestamps();
            $table->unique(['patient_id', 'related_patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_relations');
        Schema::dropIfExists('patients');
    }
};
