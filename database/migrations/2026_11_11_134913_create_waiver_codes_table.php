<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coduri de 6 cifre pentru semnarea declarației pe tableta de la recepție: unul pentru fiecare jucător,
 * folosibil o singură dată, valabil câteva ore. Codul se caută după amprenta HMAC; textul e criptat
 * doar ca recepția să-l poată afișa din nou până la folosire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waiver_codes', function (Blueprint $table) {
            $table->id();
            $table->char('code_hash', 64)->index();
            $table->text('code');
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('game_session_id')->nullable()->constrained('game_sessions')->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->foreignId('player_waiver_id')->nullable()->constrained('player_waivers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waiver_codes');
    }
};
