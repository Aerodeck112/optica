<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // identificatori tehnici ai procesatorului (ex. payment_intent) — niciodată date de card
            $table->json('provider_data')->nullable()->after('provider_reference');
        });
        Schema::table('refunds', function (Blueprint $table) {
            $table->string('method', 20)->nullable()->after('amount_minor'); // provider | manual
            $table->text('error')->nullable()->after('provider_reference');
            $table->string('rejection_reason')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('provider_data'));
        Schema::table('refunds', fn (Blueprint $table) => $table->dropColumn(['method', 'error', 'rejection_reason']));
    }
};
