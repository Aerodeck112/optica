<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Patients waiting for an earlier slot (CAL-02).
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('specialist_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contact_name', 200)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('locale', 5)->default('ro');
            $table->date('preferred_from');
            $table->date('preferred_to');
            $table->string('notes', 500)->nullable();
            // waiting | booked | removed
            $table->string('status', 20)->default('waiting');
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['location_id', 'status', 'preferred_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
