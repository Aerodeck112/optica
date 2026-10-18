<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One account per channel and provider; credentials are encrypted (COM-01).
        Schema::create('channel_accounts', function (Blueprint $table) {
            $table->id();
            // email | sms | whatsapp
            $table->string('channel', 10);
            // smtp | twilio | whatsapp_cloud
            $table->string('driver', 30);
            $table->string('name', 120);
            $table->longText('credentials')->nullable();
            // Sender identity shown to the patient (email address, SMS sender ID, WhatsApp number).
            $table->string('sender', 190)->nullable();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(false);
            $table->unsignedSmallInteger('per_minute_limit')->default(30);
            // Estimated cost per message or SMS segment, in bani (MKT-03: estimate ≠ reported cost).
            $table->unsignedInteger('estimated_unit_cost_cents')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_result', 300)->nullable();
            $table->timestamps();
            $table->index(['channel', 'is_active']);
        });

        // RO/HU templates per message type and channel, optionally per location (COM-02, TRD-05).
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('channel', 10);
            $table->string('locale', 5);
            $table->foreignId('location_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('subject', 200)->nullable();
            $table->text('body');
            // WhatsApp: the template approved by Meta and its review state (WAP-01).
            $table->string('provider_template', 120)->nullable();
            // not_required | draft | submitted | approved | rejected
            $table->string('provider_status', 20)->default('not_required');
            $table->boolean('ascii_only')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['type', 'channel', 'locale', 'location_id']);
        });

        // Which operational messages are sent, on which channels and in which order (COM-02, COM-05).
        Schema::create('message_rules', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->foreignId('location_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            // Ordered list, e.g. ["whatsapp","sms"]: the next one is used only if the previous failed definitively.
            $table->json('channels');
            // Reminders: hours before the appointment.
            $table->unsignedSmallInteger('offset_hours')->nullable();
            $table->timestamps();
            $table->unique(['type', 'location_id']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('channel', 10);
            // Allowed segments only: language, location (MKT-01). Never clinical data.
            $table->json('segment');
            $table->json('content');
            // draft | scheduled | sending | paused | stopped | done
            $table->string('status', 20)->default('draft');
            $table->dateTime('scheduled_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 40);
            // operational | marketing | test
            $table->string('category', 12);
            $table->string('channel', 10);
            $table->foreignId('account_id')->nullable()->constrained('channel_accounts')->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('locale', 5);
            $table->string('recipient', 190);
            $table->string('subject', 200)->nullable();
            $table->text('body');
            // Values of the template variables (WhatsApp templates are filled by position). Never clinical.
            $table->json('vars')->nullable();
            // Appointment time the message was written for; a different time at sending means it is stale.
            $table->dateTime('context_starts_at')->nullable();
            // pending | sending | accepted | sent | delivered | failed | uncertain | cancelled | skipped
            $table->string('status', 12)->default('pending');
            $table->string('status_reason', 300)->nullable();
            $table->string('provider_message_id', 190)->nullable()->unique();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('send_after');
            $table->dateTime('next_attempt_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedSmallInteger('segments')->nullable();
            $table->unsignedInteger('cost_estimated_cents')->nullable();
            $table->unsignedInteger('cost_reported_cents')->nullable();
            $table->foreignId('fallback_of_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'send_after']);
            $table->index(['patient_id', 'created_at']);
            $table->index(['appointment_id', 'type']);
        });

        // Provider callbacks, stored once per provider event (COM-03).
        Schema::create('message_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('source', 20);
            $table->string('event_key', 190)->unique();
            $table->string('status', 20);
            $table->string('detail', 300)->nullable();
            $table->timestamp('created_at');
        });

        // The link in reminders needs the manage token; it is kept encrypted, the lookup uses the hash.
        Schema::table('appointments', function (Blueprint $table) {
            $table->text('manage_token_encrypted')->nullable()->after('manage_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $t) => $t->dropColumn('manage_token_encrypted'));
        foreach (['message_events', 'messages', 'campaigns', 'message_rules', 'message_templates', 'channel_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
