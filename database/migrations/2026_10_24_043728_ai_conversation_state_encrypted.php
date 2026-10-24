<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // starea conversației (inclusiv mesajele utilizatorului) se păstrează criptată
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->longText('state')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->json('state')->nullable()->change();
        });
    }
};
