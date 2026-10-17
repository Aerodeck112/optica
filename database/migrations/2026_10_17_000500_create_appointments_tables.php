<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('location_id')->constrained();
            $table->foreignId('specialist_id')->constrained();
            $table->foreignId('service_id')->constrained();
            $table->foreignId('offering_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('resource_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            // UTC instants. block_* include buffers and are used for overlap checks.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('block_starts_at');
            $table->dateTime('block_ends_at');
            // held | pending | confirmed | arrived | in_consultation | completed | cancelled | no_show | expired
            $table->string('status', 20);
            // online | reception | phone | crm | import
            $table->string('source', 20);
            $table->string('locale', 5)->default('ro');
            $table->string('contact_name', 200)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('contact_email', 190)->nullable();
            $table->text('admin_notes')->nullable();
            $table->dateTime('hold_expires_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 40)->nullable();
            $table->text('cancel_note')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rescheduled_from_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->unsignedSmallInteger('reschedule_count')->default(0);
            $table->boolean('notifications_disabled')->default(false);
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->unsignedInteger('lock_version')->default(1);
            $table->string('external_ref', 120)->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['specialist_id', 'block_starts_at', 'block_ends_at'], 'appt_specialist_block_idx');
            $table->index(['location_id', 'starts_at']);
            $table->index(['patient_id', 'starts_at']);
            $table->index(['status', 'hold_expires_at']);
            $table->index(['resource_id', 'block_starts_at']);
        });

        Schema::create('appointment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            // created | status_changed | rescheduled | updated | cancelled | expired
            $table->string('type', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->dateTime('old_starts_at')->nullable();
            $table->dateTime('new_starts_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // user | patient | system
            $table->string('actor', 10)->default('user');
            $table->json('payload')->nullable();
            $table->dateTime('created_at');
            $table->index(['appointment_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });

        // A visit is the consultation that actually happened; distinct from the appointment (CAL-03).
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('location_id')->constrained();
            $table->foreignId('specialist_id')->constrained();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->string('external_ref', 120)->nullable()->index();
            $table->timestamps();
            $table->index(['patient_id', 'started_at']);
            $table->index(['location_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
        Schema::dropIfExists('appointment_events');
        Schema::dropIfExists('appointments');
    }
};
