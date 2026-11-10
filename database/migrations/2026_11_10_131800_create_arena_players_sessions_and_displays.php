<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Modulul „Ecrane afișare”:
 *  - jucători (profiluri distincte de clientul care rezervă), cu nickname moderat, avatar și preferința de afișare pe ecrane;
 *  - sesiuni de joc efective (pornite de operator) și participanții lor, cu rezultate introduse manual sau sincronizate;
 *  - ecranele din arenă, playlisturile lor și biblioteca promoțională.
 * Profilurile existente (clienți) rămân neatinse: nu se creează jucători și nu se generează nickname-uri din nume.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete(); // clientul / adultul responsabil
            $table->text('private_name')->nullable(); // criptat; doar în CRM
            $table->string('identity_hash', 64)->nullable()->index(); // recunoașterea aceleiași persoane între declarații
            $table->boolean('is_minor')->default(false);
            $table->string('nickname', 40)->nullable();
            $table->string('nickname_normalized', 40)->nullable()->unique();
            $table->string('nickname_status', 12)->default('missing'); // missing | review | approved | blocked
            $table->string('nickname_source', 12)->nullable(); // staff | online
            $table->foreignId('nickname_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('nickname_reviewed_at')->nullable();
            $table->string('avatar_key', 40);
            $table->string('display_consent', 12)->default('unknown'); // unknown | granted | refused | withdrawn
            $table->string('display_consent_source', 20)->nullable(); // waiver | reception | crm
            $table->text('display_consent_guardian')->nullable(); // criptat; adultul care a decis pentru un minor
            $table->timestamp('display_consent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('nickname_status');
        });

        Schema::create('nickname_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('normalized', 40)->unique();
            $table->string('label', 60);
            $table->string('reason', 190)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('games', function (Blueprint $table) {
            $table->string('result_type', 16)->default('none')->after('intensity'); // none | score_high | time_low | team_score
        });

        Schema::create('game_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignId('resource_id')->constrained('resources')->restrictOnDelete();
            $table->foreignId('game_id')->nullable()->constrained('games')->nullOnDelete();
            $table->string('status', 12)->default('scheduled'); // scheduled | checkin | live | paused | completed | cancelled
            $table->boolean('is_test')->default(false);
            $table->timestamp('scheduled_at')->nullable();
            $table->unsignedSmallInteger('planned_minutes')->default(60);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('paused_seconds')->default(0);
            $table->timestamp('ended_at')->nullable();
            $table->string('result_type', 16)->default('none');
            $table->string('results_source', 8)->nullable(); // manual | sync
            $table->timestamp('results_entered_at')->nullable();
            $table->foreignId('results_entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['resource_id', 'status']);
            $table->index(['status', 'ended_at']);
        });

        Schema::create('session_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_session_id')->constrained('game_sessions')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('players')->restrictOnDelete();
            $table->unsignedSmallInteger('slot');
            $table->string('status', 12)->default('registered'); // registered | active | removed | no_show
            $table->timestamp('activated_at')->nullable();
            $table->bigInteger('score')->nullable();
            $table->unsignedInteger('time_ms')->nullable();
            $table->string('team', 30)->nullable();
            $table->unsignedSmallInteger('place')->nullable();
            $table->string('result_source', 8)->nullable(); // manual | sync
            $table->boolean('counts')->default(true); // participare eligibilă pentru clasamentul de fidelitate
            $table->string('correction_note', 255)->nullable();
            $table->timestamps();
            $table->unique(['game_session_id', 'player_id']);
            $table->index(['player_id', 'status']);
        });

        Schema::table('player_waivers', function (Blueprint $table) {
            $table->foreignId('player_id')->nullable()->after('booking_id')->constrained('players')->nullOnDelete();
            $table->boolean('screen_display')->default(false)->after('photo_consent');
        });

        Schema::create('display_playlists', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('mode', 12)->default('smart'); // smart (aleatoriu controlat) | sequential
            $table->unsignedSmallInteger('min_main_seconds')->default(45);
            $table->unsignedTinyInteger('max_consecutive_ads')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('display_assets', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->foreignId('media_id')->constrained('media')->restrictOnDelete();
            $table->string('kind', 10); // image | video
            $table->string('caption', 160)->nullable();
            $table->string('fit', 10)->default('cover'); // cover | contain
            $table->unsignedTinyInteger('weight')->default(5);
            $table->unsignedSmallInteger('duration_seconds')->default(8);
            $table->string('screens', 16)->default('all'); // all | live_arena | hall_of_legends
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('display_playlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('display_playlist_id')->constrained('display_playlists')->cascadeOnDelete();
            $table->string('type', 20); // main | leaderboard | podium | promo | video | logo | clock | game | message
            $table->foreignId('display_asset_id')->nullable()->constrained('display_assets')->nullOnDelete();
            $table->foreignId('game_id')->nullable()->constrained('games')->nullOnDelete();
            $table->unsignedSmallInteger('duration_seconds');
            $table->unsignedTinyInteger('weight')->default(5);
            $table->string('message', 200)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->time('time_from')->nullable();
            $table->time('time_until')->nullable();
            $table->json('weekdays')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('display_screens', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 120);
            $table->string('type', 20); // live_arena | hall_of_legends
            $table->foreignId('resource_id')->nullable()->constrained('resources')->nullOnDelete();
            $table->foreignId('display_playlist_id')->nullable()->constrained('display_playlists')->nullOnDelete();
            $table->json('settings')->nullable();
            $table->json('override')->nullable(); // comandă rapidă temporară: {command, asset_id, expires_at}
            $table->string('device_token_hash', 64)->nullable()->unique();
            $table->string('device_label', 120)->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_data_at')->nullable();
            $table->string('player_version', 20)->nullable();
            $table->string('resolution', 20)->nullable();
            $table->string('user_agent', 190)->nullable();
            $table->json('telemetry')->nullable();
            $table->timestamp('reload_requested_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('display_pairings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('display_screen_id')->constrained('display_screens')->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->defaults();
        (new RolesAndPermissionsSeeder)->run();
    }

    /** Configurația inițială (editabilă din CRM): cele două ecrane, playlisturile lor și materialele existente din biblioteca media. */
    private function defaults(): void
    {
        $now = now();
        $assets = [];
        if (DB::table('media')->exists()) {
            $assets = $this->assets($now);
        }

        $live = DB::table('display_playlists')->insertGetId(['name' => 'Live Arena — implicit', 'mode' => 'smart', 'min_main_seconds' => 45, 'max_consecutive_ads' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $legends = DB::table('display_playlists')->insertGetId(['name' => 'Hall of Legends — implicit', 'mode' => 'smart', 'min_main_seconds' => 45, 'max_consecutive_ads' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        $items = [
            $live => [['main', 50, 10], ['promo', 8, 5], ['game', 10, 4], ['video', 20, 3], ['logo', 5, 2], ['clock', 6, 2]],
            $legends => [['leaderboard', 50, 10], ['podium', 12, 4], ['promo', 8, 5], ['video', 20, 3], ['logo', 5, 2], ['clock', 6, 2]],
        ];
        foreach ($items as $playlist => $rows) {
            foreach ($rows as $i => [$type, $seconds, $weight]) {
                DB::table('display_playlist_items')->insert([
                    'display_playlist_id' => $playlist, 'type' => $type, 'duration_seconds' => $seconds, 'weight' => $weight,
                    'is_active' => true, 'sort' => $i * 10, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        $arena = DB::table('resources')->where('type', 'arena')->whereNull('parent_id')->orderBy('id')->value('id');
        DB::table('display_screens')->insert([
            ['slug' => 'arena-live', 'name' => 'Ecran 1 — Live Arena', 'type' => 'live_arena', 'resource_id' => $arena, 'display_playlist_id' => $live, 'settings' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'hall-of-legends', 'name' => 'Ecran 2 — Hall of Legends', 'type' => 'hall_of_legends', 'resource_id' => $arena, 'display_playlist_id' => $legends, 'settings' => null, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /** Materiale promoționale inițiale: imagini de brand, fotografii ale rețelei și trailerele jocurilor deja publicate. */
    private function assets($now): array
    {
        $images = ['cosmos-planet', 'cosmos-cliffs', 'cosmos-red-planet', 'cosmos-city', 'social-echipa-in-misiune', 'social-trei-jucatori-actiune', 'social-echipa-ocheste', 'social-doi-jucatori-actiune'];
        $ids = [];
        foreach (DB::table('media')->whereIn('static_key', $images)->where('rights_status', '!=', 'unknown')->whereNull('deleted_at')->get() as $media) {
            $ids[] = DB::table('display_assets')->insertGetId([
                'title' => Str::of($media->static_key)->replace(['social-', 'cosmos-'], ['', 'Cosmos '])->replace('-', ' ')->ucfirst()->toString(),
                'media_id' => $media->id, 'kind' => 'image', 'fit' => 'cover', 'weight' => 5, 'duration_seconds' => 8,
                'screens' => 'all', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach (DB::table('media')->where('kind', 'video')->where('static_key', 'like', 'trailer-%')->where('rights_status', '!=', 'unknown')->whereNull('deleted_at')->get() as $media) {
            $ids[] = DB::table('display_assets')->insertGetId([
                'title' => 'Trailer '.Str::of($media->static_key)->after('trailer-')->replace('-', ' ')->title()->toString(),
                'media_id' => $media->id, 'kind' => 'video', 'fit' => 'contain', 'weight' => 4, 'duration_seconds' => (int) ($media->duration_seconds ?: 30),
                'screens' => 'all', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return $ids;
    }

    public function down(): void
    {
        Schema::dropIfExists('display_pairings');
        Schema::dropIfExists('display_screens');
        Schema::dropIfExists('display_playlist_items');
        Schema::dropIfExists('display_assets');
        Schema::dropIfExists('display_playlists');
        Schema::table('player_waivers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('player_id');
            $table->dropColumn('screen_display');
        });
        Schema::dropIfExists('session_participants');
        Schema::dropIfExists('game_sessions');
        Schema::table('games', fn (Blueprint $table) => $table->dropColumn('result_type'));
        Schema::dropIfExists('nickname_blocks');
        Schema::dropIfExists('players');
    }
};
