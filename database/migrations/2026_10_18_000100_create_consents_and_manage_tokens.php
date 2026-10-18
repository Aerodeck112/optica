<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Information notices, required agreements and marketing preferences per channel,
        // each with version, date, source and withdrawal (cap. 6 "Consimțăminte", MKT-02).
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            // Contact the consent belongs to when no patient record exists yet.
            $table->string('contact_phone', 20)->nullable()->index();
            $table->string('contact_email', 190)->nullable()->index();
            // privacy_notice | booking_terms | marketing
            $table->string('purpose', 30);
            // null for notices; email | sms | whatsapp for marketing
            $table->string('channel', 20)->nullable();
            $table->boolean('granted');
            $table->string('text_version', 40);
            $table->string('locale', 5);
            // online_booking | contact_form | reception | import | unsubscribe_link
            $table->string('source', 30);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('given_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['purpose', 'channel', 'granted']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            // Hash of the patient's private cancel/reschedule link (PRG-05). The token itself is never stored.
            $table->string('manage_token_hash', 64)->nullable()->unique()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('manage_token_hash');
        });
        Schema::dropIfExists('consents');
    }
};
