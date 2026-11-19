<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ecranul din lounge (televizorul cu PC din zona părinților): camerele din sala de joc, iar între ele materialele
 * Another World (promo, jocuri, logo). Camerele vin de la go2rtc, pe PC-ul televizorului; aici doar ecranul și playlistul.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('display_screens')->where('slug', 'lounge')->exists()) {
            return;
        }
        $now = now();
        $playlist = DB::table('display_playlists')->insertGetId(['name' => 'Lounge — camere și reclame', 'mode' => 'smart', 'min_main_seconds' => 60, 'max_consecutive_ads' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        foreach ([['main', 75, 10], ['promo', 10, 5], ['game', 12, 4], ['video', 20, 3], ['logo', 6, 2]] as $i => [$type, $seconds, $weight]) {
            DB::table('display_playlist_items')->insert([
                'display_playlist_id' => $playlist, 'type' => $type, 'duration_seconds' => $seconds, 'weight' => $weight,
                'is_active' => true, 'sort' => $i * 10, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('display_screens')->insert([
            'slug' => 'lounge', 'name' => 'Ecran 3 — Lounge (camere)', 'type' => 'lounge',
            'resource_id' => DB::table('resources')->where('type', 'arena')->whereNull('parent_id')->orderBy('id')->value('id'),
            'display_playlist_id' => $playlist, 'settings' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $playlist = DB::table('display_screens')->where('slug', 'lounge')->value('display_playlist_id');
        DB::table('display_screens')->where('slug', 'lounge')->delete();
        DB::table('display_playlist_items')->where('display_playlist_id', $playlist)->delete();
        DB::table('display_playlists')->where('id', $playlist)->delete();
    }
};
