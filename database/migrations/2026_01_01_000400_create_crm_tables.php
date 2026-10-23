<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clientul este întotdeauna persoana adultă responsabilă (sau compania). Datele copiilor nu se stochează aici.
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10)->default('person'); // person | company
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('email_normalized', 190)->nullable()->index();
            $table->string('phone', 40)->nullable();
            $table->string('phone_normalized', 20)->nullable()->index(); // E.164
            $table->string('company_name', 190)->nullable();
            $table->string('company_tax_id', 20)->nullable(); // CUI / CIF
            $table->string('company_reg_no', 40)->nullable(); // J..../....
            $table->string('billing_address', 255)->nullable();
            $table->string('billing_city', 120)->nullable();
            $table->string('billing_county', 120)->nullable();
            $table->string('billing_country', 2)->default('RO');
            $table->string('locale', 5)->default('ro');
            $table->json('tags')->nullable();
            $table->json('interests')->nullable();         // id-uri de jocuri/tipuri de experiență declarate
            $table->string('source', 30)->nullable();      // web | ai | reception | phone | import | admin
            $table->unsignedInteger('bookings_count')->default(0);
            $table->unsignedInteger('visits_count')->default(0);
            $table->unsignedBigInteger('paid_total_minor')->default(0);
            $table->timestamp('first_booking_at')->nullable();
            $table->timestamp('last_booking_at')->nullable();
            $table->timestamp('last_visit_at')->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamp('anonymized_at')->nullable();
            $table->string('referral_code', 20)->nullable()->unique();
            $table->foreignId('loyalty_tier_id')->nullable();
            $table->timestamps();
            $table->index(['last_booking_at']);
        });

        Schema::create('customer_login_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('customer_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->string('visibility', 20)->default('staff'); // staff | managers
            $table->timestamps();
        });

        // Starea curentă a consimțământului pe canal. Istoricul complet este în consent_events.
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 10);                 // email | sms
            $table->string('purpose', 30)->default('marketing');
            $table->string('status', 12);                  // granted | withdrawn
            $table->string('source', 30);                  // booking_form | newsletter_form | account | import | reception | admin
            $table->json('proof')->nullable();             // textul afișat, versiunea politicii, IP hash, user agent, id import
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'channel', 'purpose']);
        });

        Schema::create('consent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 10);
            $table->string('purpose', 30)->default('marketing');
            $table->string('action', 12);                  // granted | withdrawn
            $table->string('source', 30);
            $table->json('proof')->nullable();
            $table->string('actor_type', 20)->default('customer');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Listă de excludere: verificată înaintea oricărui mesaj de marketing (și a mesajelor de serviciu, pentru bounce/complaint).
        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 10);                 // email | sms
            $table->string('value_hash', 64);              // sha256 al adresei normalizate
            $table->string('reason', 20);                  // unsubscribed | hard_bounce | complaint | manual | invalid
            $table->string('scope', 20)->default('marketing'); // marketing | all
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->unique(['channel', 'value_hash', 'scope']);
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);                    // birthday | corporate | private | group | other
            $table->string('stage', 20)->default('new');   // new | contacted | offer_sent | negotiating | confirmed | completed | lost
            $table->string('contact_name', 190);
            $table->string('contact_email', 190)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('company_name', 190)->nullable();
            $table->date('preferred_date')->nullable();
            $table->string('preferred_time', 40)->nullable();
            $table->json('alternative_dates')->nullable();
            $table->unsignedSmallInteger('participants')->nullable();
            $table->json('age_groups')->nullable();        // doar categorii agregate, fără date individuale
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->text('message')->nullable();
            $table->unsignedBigInteger('offer_amount_minor')->nullable();
            $table->string('lost_reason', 255)->nullable();
            $table->string('source', 20)->default('web');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('booking_id')->nullable()->index();
            $table->timestamp('stage_changed_at')->nullable();
            $table->timestamps();
            $table->index(['stage', 'preferred_date']);
        });

        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);                    // note | call | email | stage_change | offer
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('related');
            $table->string('priority', 10)->default('normal');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['assigned_to', 'completed_at', 'due_at']);
        });
    }

    public function down(): void
    {
        foreach (['tasks', 'lead_activities', 'leads', 'suppressions', 'consent_events', 'consents', 'customer_notes', 'customer_login_tokens', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
