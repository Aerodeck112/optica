<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            // imaginile cu minori se publică doar după confirmarea explicită a acordului părinților/tutorilor
            $table->boolean('guardian_consent_confirmed')->default(false)->after('depicts_minors');
            $table->foreignId('guardian_consent_confirmed_by')->nullable()->after('guardian_consent_confirmed');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn(['guardian_consent_confirmed', 'guardian_consent_confirmed_by']);
        });
    }
};
