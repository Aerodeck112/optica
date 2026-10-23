<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60);                     // booking.request_received, booking.confirmed, booking.reminder_24h ...
            $table->string('channel', 10);                 // email | sms
            $table->string('locale', 5)->default('ro');
            $table->string('category', 12)->default('service'); // service | marketing
            $table->string('subject', 255)->nullable();
            $table->text('body');                          // text cu variabile {{ booking.code }}
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['key', 'channel', 'locale']);
        });

        // Remindere și mesaje programate pentru rezervări. Recalculate la reprogramare, anulate la anulare.
        Schema::create('scheduled_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('template_key', 60);
            $table->string('channel', 10);
            $table->timestamp('send_at')->index();
            $table->string('status', 12)->default('pending'); // pending | processing | sent | cancelled | skipped | failed
            $table->string('idempotency_key', 120)->unique();
            $table->string('claim_token', 36)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->string('skip_reason', 120)->nullable();
            $table->unsignedBigInteger('message_log_id')->nullable();
            $table->timestamps();
            $table->index(['status', 'send_at']);
        });

        // Jurnalul fiecărui mesaj. Stările de livrare sunt distincte.
        Schema::create('message_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('channel', 10);
            $table->string('category', 12);                // service | marketing
            $table->string('template_key', 60)->nullable();
            $table->string('recipient_masked', 190);       // ex: an***@gm***.com
            $table->string('recipient_hash', 64)->index();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('booking_id')->nullable()->index();
            $table->unsignedBigInteger('campaign_id')->nullable()->index();
            $table->string('subject', 255)->nullable();
            $table->string('provider', 30)->nullable();
            $table->string('provider_message_id', 190)->nullable()->index();
            $table->string('status', 20)->default('queued'); // queued | sending | accepted | delivered | failed | bounced | complained | suppressed | sandboxed
            $table->string('idempotency_key', 160)->nullable()->unique();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('cost_micro')->nullable(); // cost SMS estimat/raportat (micro-unități RON)
            $table->unsignedSmallInteger('segments')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('opened_at')->nullable();    // indicativ, nu măsurătoare exactă
            $table->timestamp('clicked_at')->nullable();
            $table->unsignedBigInteger('resent_from_id')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('segments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->json('rules');                         // [{"type":"recurring","min_bookings":2}, ...]
            $table->unsignedInteger('cached_count')->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('channel', 10);                 // email | sms
            $table->foreignId('segment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('locale', 5)->default('ro');
            $table->string('subject', 255)->nullable();
            $table->string('preheader', 255)->nullable();
            $table->text('body');
            $table->string('status', 20)->default('draft'); // draft | scheduled | sending | sent | cancelled | paused
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('test_sent_at')->nullable();
            $table->unsignedBigInteger('cost_cap_minor')->nullable(); // plafon SMS
            $table->unsignedBigInteger('estimated_cost_minor')->nullable();
            $table->json('stats')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending'); // pending | sent | skipped
            $table->string('skip_reason', 60)->nullable(); // no_consent | suppressed | frequency_cap | cost_cap | invalid
            $table->unsignedBigInteger('message_log_id')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'customer_id']);
            $table->index(['campaign_id', 'status']);
        });

        Schema::create('contact_imports', function (Blueprint $table) {
            $table->id();
            $table->string('filename', 255);
            $table->string('source_description', 500);     // de unde provin contactele
            $table->text('permission_proof');              // cum a fost obținută permisiunea
            $table->json('channels');                      // canalele pentru care există permisiune
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_imported')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->json('errors')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['contact_imports', 'campaign_recipients', 'campaigns', 'segments', 'message_logs', 'scheduled_notifications', 'notification_templates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
