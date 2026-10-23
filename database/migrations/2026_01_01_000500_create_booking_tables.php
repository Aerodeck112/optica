<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Arena și, opțional, zonele ei. Rândul resursei este blocat (FOR UPDATE) la fiecare ocupare.
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('resources')->nullOnDelete();
            $table->string('name', 120);
            $table->string('type', 20)->default('arena');  // arena | zone | room | equipment
            $table->unsignedSmallInteger('capacity')->nullable(); // jucători simultan; null = neconfigurat
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);    // rezervabilă online
            $table->string('color', 7)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('resource_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('type', 20)->default('internal'); // maintenance | internal | private_event | closed
            $table->string('reason', 255)->nullable();
            $table->uuid('series_id')->nullable()->index();  // blocări recurente generate împreună
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['resource_id', 'starts_at', 'ends_at']);
        });

        Schema::create('slot_holds', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedSmallInteger('participants')->default(1);
            $table->boolean('is_exclusive')->default(true);
            $table->string('token_hash', 64);
            $table->string('source', 20)->default('web');
            $table->timestamp('expires_at')->index();
            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->timestamps();
            $table->index(['resource_id', 'starts_at', 'ends_at']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('game_id')->nullable()->constrained()->nullOnDelete(); // jocul preferat, dacă a fost ales
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('starts_at')->nullable();    // începutul experienței (UTC); pentru cereri = dorința clientului
            $table->timestamp('ends_at')->nullable();
            $table->unsignedSmallInteger('participants')->default(1);
            $table->json('age_groups')->nullable();        // {"under_12": 4, "12_17": 2, "adults": 1} — fără date individuale
            $table->string('status', 20)->default('request'); // request | pending | confirmed | checked_in | completed | cancelled | no_show | expired
            $table->string('payment_status', 20)->default('unpaid'); // unpaid | deposit_paid | partially_paid | paid | partially_refunded | refunded
            $table->string('invoice_status', 20)->default('not_invoiced'); // not_invoiced | proforma | invoiced
            $table->string('source', 20)->default('web');  // web | ai | reception | admin | phone | lead
            $table->string('payment_method', 20)->nullable(); // at_location | bank_transfer | online
            // snapshot contact (adultul care rezervă)
            $table->string('contact_name', 190);
            $table->string('contact_email', 190)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('company_name', 190)->nullable();
            $table->text('customer_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // bani (minor units)
            $table->string('currency', 3)->default('RON');
            $table->unsignedBigInteger('total_minor')->nullable();     // null = preț de stabilit (cerere)
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('deposit_minor')->default(0);
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->json('price_snapshot')->nullable();     // pachet, reguli de preț aplicate, TVA
            $table->json('accepted_policies')->nullable();  // [{type, version, hash, accepted_at}]
            $table->string('manage_token_hash', 64)->nullable()->unique();
            $table->text('manage_token_encrypted')->nullable(); // pentru linkul din remindere (criptat cu cheia aplicației)
            $table->string('checkin_token_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();   // pentru pending: termenul de plată/confirmare
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->string('cancelled_by', 20)->nullable(); // customer | staff | system
            $table->unsignedSmallInteger('reschedule_count')->default(0);
            $table->string('locale', 5)->default('ro');
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            $table->string('analytics_client_ref', 64)->nullable(); // id anonim pentru conversii, fără date personale
            $table->timestamps();
            $table->index(['status', 'starts_at']);
            $table->index(['starts_at']);
        });

        Schema::create('booking_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');                // include pauza dinainte
            $table->timestamp('ends_at');                  // include pauza de după
            $table->unsignedSmallInteger('participants')->default(1);
            $table->boolean('is_exclusive')->default(true);
            $table->boolean('is_active')->default(true);   // false după anulare/expirare → nu mai ocupă
            $table->timestamps();
            $table->index(['resource_id', 'is_active', 'starts_at', 'ends_at'], 'booking_resources_overlap_idx');
        });

        Schema::create('booking_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                    // package | addon | extra_person | discount | voucher | manual
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description', 255);
            $table->decimal('quantity', 10, 3)->default(1);
            $table->bigInteger('unit_price_minor');        // cu TVA inclus
            $table->bigInteger('total_minor');
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->string('vat_group', 40)->nullable();
            $table->timestamps();
        });

        Schema::create('booking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);                    // created | status_changed | rescheduled | payment | note | notification | checkin
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('actor_type', 20)->default('system'); // customer | user | system | ai | webhook
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['booking_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['booking_events', 'booking_items', 'booking_resources', 'bookings', 'slot_holds', 'resource_blocks', 'resources'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
