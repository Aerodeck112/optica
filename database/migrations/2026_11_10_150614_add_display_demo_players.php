<?php

use App\Modules\Arena\Services\NicknamePolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Jucători DEMONSTRATIVI pentru ecranul Hall of Legends, ceruți de proprietar (10.11.2026) ca ecranul să arate plin
 * până apar primele legende reale. Sunt marcați is_demo, nu au date personale și se ascund din
 * Setări → Ecrane afișare („Afișează jucătorii demonstrativi”) sau se șterg din Ecrane afișare → Ecrane → „Șterge datele demonstrative”.
 */
return new class extends Migration
{
    /** nickname => [avatar, aventuri] */
    public const PLAYERS = [
        'NovaRider' => ['astronaut-05', 14],
        'Lupul_Alb' => ['creature-02', 11],
        'CometaRoz' => ['fantasy-05', 9],
        'PixelPirat' => ['robot-04', 7],
        'Crăiasa' => ['mask-03', 5],
    ];

    public function up(): void
    {
        Schema::table('players', fn (Blueprint $table) => $table->boolean('is_demo')->default(false)->after('is_minor'));
        Schema::table('game_sessions', fn (Blueprint $table) => $table->boolean('is_demo')->default(false)->after('is_test'));

        // doar pe site-ul instalat (cu prima pagină și o arenă)
        $arena = DB::table('resources')->where('type', 'arena')->whereNull('parent_id')->orderBy('id')->value('id');
        if (! $arena || ! DB::table('pages')->where('key', 'home')->exists()) {
            return;
        }
        $now = now();
        $games = DB::table('games')->whereIn('cluj_status', ['available', 'coming_soon'])->orderBy('sort')->pluck('id')->all();
        $ids = [];
        foreach (self::PLAYERS as $nickname => [$avatar, $count]) {
            $normalized = NicknamePolicy::normalize($nickname);
            $existing = DB::table('players')->where('nickname_normalized', $normalized)->value('id');
            if ($existing) {
                continue; // un jucător real folosește deja nickname-ul: nu îl atingem
            }
            $ids[$nickname] = DB::table('players')->insertGetId([
                'uuid' => (string) Str::uuid(), 'nickname' => $nickname, 'nickname_normalized' => $normalized, 'nickname_status' => 'approved',
                'nickname_source' => 'staff', 'avatar_key' => $avatar, 'display_consent' => 'granted', 'display_consent_source' => 'crm',
                'display_consent_at' => $now, 'is_minor' => false, 'is_demo' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        if (! $ids) {
            return;
        }

        // sesiuni demonstrative finalizate în ultimele două săptămâni; jucătorul i participă la primele N sesiuni
        $max = max(array_map(fn ($p) => $p[1], self::PLAYERS));
        for ($s = 0; $s < $max; $s++) {
            $ended = $now->copy()->subDays(14 - $s)->setTime(17 + $s % 4, 0);
            $session = DB::table('game_sessions')->insertGetId([
                'uuid' => (string) Str::uuid(), 'resource_id' => $arena, 'game_id' => $games ? $games[$s % count($games)] : null,
                'status' => 'completed', 'is_test' => false, 'is_demo' => true, 'planned_minutes' => 60,
                'started_at' => $ended->copy()->subHour(), 'ended_at' => $ended, 'result_type' => 'none', 'notes' => 'Sesiune de prezentare (Hall of Legends).',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $slot = 0;
            foreach (self::PLAYERS as $nickname => [, $count]) {
                if (isset($ids[$nickname]) && $s < $count) {
                    DB::table('session_participants')->insert([
                        'game_session_id' => $session, 'player_id' => $ids[$nickname], 'slot' => ++$slot, 'status' => 'active',
                        'activated_at' => $ended->copy()->subHour(), 'counts' => true, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }

        DB::table('settings')->updateOrInsert(['key' => 'display.show_demo'], ['value' => '1', 'is_encrypted' => false, 'created_at' => $now, 'updated_at' => $now]);
        Cache::forget('aw.settings.v1');
        try {
            Cache::increment('display:data-version');
        } catch (Throwable) {
        }
    }

    public function down(): void
    {
        Schema::table('game_sessions', fn (Blueprint $table) => $table->dropColumn('is_demo'));
        Schema::table('players', fn (Blueprint $table) => $table->dropColumn('is_demo'));
    }
};
