<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cardurile fizice pentru tabletă (AWC-001 … AWC-020): fiecare are un cod fix de 6 cifre, tipărit și ca cod de bare.
 * Codul de pe card funcționează doar cât timp recepția l-a dat unui grup (câteva ore) și doar pentru o semnătură;
 * după semnare cardul revine la recepție și poate fi dat următorului jucător.
 */
return new class extends Migration
{
    /** Codurile tipărite pe cele 20 de carduri, în ordinea seriei. */
    private const CODES = [
        '614971', '165972', '694509', '702812', '115596', '986583', '691220', '760421', '544287', '814970',
        '610700', '452122', '158494', '107801', '258035', '428841', '198645', '155453', '903890', '884185',
    ];

    public function up(): void
    {
        Schema::create('waiver_cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('number')->unique();
            $table->string('serial', 20)->unique();
            $table->char('code', 6)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('waiver_codes', function (Blueprint $table) {
            $table->foreignId('waiver_card_id')->nullable()->after('game_session_id')->constrained('waiver_cards')->nullOnDelete();
        });

        $now = now();
        DB::table('waiver_cards')->insert(array_map(fn (string $code, int $i) => [
            'number' => $i + 1,
            'serial' => sprintf('AWC-%03d', $i + 1),
            'code' => $code,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::CODES, array_keys(self::CODES)));
    }

    public function down(): void
    {
        Schema::table('waiver_codes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('waiver_card_id');
        });
        Schema::dropIfExists('waiver_cards');
    }
};
