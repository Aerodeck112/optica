<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pages where a text was seen in translation mode: the "page" filter of the centre (TRD-03).
        Schema::create('translation_usages', function (Blueprint $table) {
            $table->id();
            $table->string('key', 190);
            $table->string('route', 120);
            $table->timestamp('last_seen_at');
            $table->unique(['key', 'route']);
            $table->index('route');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_usages');
    }
};
