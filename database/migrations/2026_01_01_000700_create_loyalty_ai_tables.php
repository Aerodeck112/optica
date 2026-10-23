<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->unsignedInteger('min_lifetime_points')->default(0);
            $table->json('benefits')->nullable();
            $table->decimal('earn_multiplier', 4, 2)->default(1);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // Registru append-only. Soldul = suma delta; nu există sold modificabil fără istoric.
        Schema::create('loyalty_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->integer('delta');
            $table->integer('balance_after');
            $table->string('type', 20);                    // earn | redeem | reversal | adjust | expire | referral
            $table->nullableMorphs('reference');
            $table->string('idempotency_key', 120)->unique();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->json('description')->nullable();
            $table->unsignedInteger('points_cost');
            $table->string('reward_type', 20);             // voucher_value | voucher_package
            $table->unsignedBigInteger('value_minor')->nullable();
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('voucher_valid_days')->default(180);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('referred_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->string('status', 20)->default('pending'); // pending | qualified | rewarded | rejected
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamps();
            $table->unique('referred_id');
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('type', 12);                    // value | package
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('package_participants')->nullable();
            $table->unsignedBigInteger('initial_value_minor')->nullable();
            $table->bigInteger('balance_minor')->default(0); // cache, recalculat din tranzacții
            $table->boolean('allows_partial_use')->default(true);
            $table->string('currency', 3)->default('RON');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('status', 12)->default('active'); // draft | active | redeemed | expired | cancelled
            $table->json('conditions')->nullable();
            $table->string('origin', 20)->default('sale'); // sale | reward | promo | compensation
            $table->foreignId('buyer_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('recipient_name', 190)->nullable();
            $table->string('message', 500)->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('legal_document_version_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('voucher_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_minor');            // + emitere/restituire, − utilizare
            $table->bigInteger('balance_after_minor');
            $table->string('type', 12);                    // issue | redeem | refund | adjust | expire | cancel
            $table->unsignedBigInteger('booking_id')->nullable()->index();
            $table->string('idempotency_key', 120)->unique();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('ai_knowledge_entries', function (Blueprint $table) {
            $table->id();
            $table->string('topic', 120);
            $table->json('content');                       // text aprobat, traductibil
            $table->string('status', 12)->default('draft'); // draft | approved | archived
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('session_hash', 64)->index();
            $table->string('ip_hash', 64)->nullable();
            $table->string('locale', 5)->default('ro');
            $table->string('status', 20)->default('open'); // open | handed_off | closed | blocked
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->json('state')->nullable();             // context structurat (pachet ales, hold, rezumat propus)
            $table->unsignedBigInteger('verified_booking_id')->nullable();
            $table->timestamp('verified_until')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });

        // Jurnal minimizat: conținutul este redactat (e-mail/telefon mascate) și șters după perioada de retenție.
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12);                    // user | assistant | tool
            $table->text('content_redacted')->nullable();
            $table->string('tool_name', 60)->nullable();
            $table->json('tool_summary')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('ai_usage_daily', function (Blueprint $table) {
            $table->id();
            $table->date('day')->unique();
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['ai_usage_daily', 'ai_messages', 'ai_conversations', 'ai_knowledge_entries', 'voucher_transactions', 'vouchers', 'referrals', 'rewards', 'loyalty_ledger', 'loyalty_tiers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
