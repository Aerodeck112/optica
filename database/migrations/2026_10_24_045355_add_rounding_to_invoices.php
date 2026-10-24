<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // ajustare de rotunjire (EN 16931 BT-114): total de plată = total cu TVA + rotunjire
            $table->bigInteger('rounding_minor')->default(0)->after('gross_total_minor');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('rounding_minor'));
    }
};
