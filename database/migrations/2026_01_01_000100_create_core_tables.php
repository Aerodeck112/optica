<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Setări generale și datele locației. Valorile secrete sunt criptate de SettingsRepository.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->longText('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Configurarea furnizorilor externi (e-mail, SMS, plăți, AI, ANAF, analytics...).
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();          // ex: email, sms, payments, ai, efactura, fiscal
            $table->string('driver', 60)->nullable();       // ex: brevo, twilio, stripe, anthropic, anaf
            $table->longText('credentials')->nullable();    // JSON criptat
            $table->json('options')->nullable();            // setări necriptate (expeditor, mediu)
            $table->string('mode', 20)->default('disabled'); // disabled | sandbox | live
            $table->string('status', 30)->default('unconfigured'); // unconfigured | sandbox_tested | configured | error | blocked
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_result', 500)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('opening_hours', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('weekday');        // 1 = luni … 7 = duminică (ISO)
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
            $table->unique('weekday');
        });

        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->boolean('is_closed')->default(true);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->string('reason', 190)->nullable();     // intern
            $table->json('public_note')->nullable();       // afișat pe site, traductibil
            $table->timestamps();
            $table->unique('date');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 30);              // user | customer | system | ai | webhook
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 80);                  // ex: booking.cancelled, settings.updated
            $table->nullableMorphs('subject');
            $table->json('changes')->nullable();           // valori vechi/noi, cu secretele mascate
            $table->json('context')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['action', 'created_at']);
        });

        // Toate webhook-urile primite (plăți, e-mail, SMS) — idempotență prin (provider, event_id).
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('event_id', 190);
            $table->string('type', 120)->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('status', 20)->default('received'); // received | processed | ignored | failed
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_id']);
        });

        Schema::create('saved_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('resource', 120);
            $table->string('name', 120);
            $table->json('filters');
            $table->boolean('is_shared')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_filters');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('schedule_exceptions');
        Schema::dropIfExists('opening_hours');
        Schema::dropIfExists('integrations');
        Schema::dropIfExists('settings');
    }
};
