<?php

namespace Database\Seeders;

use App\Modules\Arena\Models\GameSession;
use App\Modules\Arena\Models\Player;
use App\Modules\Arena\Services\GameSessionService;
use App\Modules\Arena\Services\PlayerService;
use App\Modules\Booking\Models\Resource;
use App\Modules\Catalog\Models\Game;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Date DEMONSTRATIVE pentru ecranele din arenă (doar în mediul demo/local; refuză producția).
 * Jucătorii sunt fictivi și au numele privat marcat „[DEMO]”; nu se rulează niciodată pe site-ul real.
 */
class DisplayDemoSeeder extends Seeder
{
    public const NICKNAMES = ['NovaRider', 'Ștefania', 'PixelPirat', 'Lupul_Alb', 'CometaRoz', 'Zorbix', 'MiraQuest', 'TudorX', 'Ana.Maria',
        'NeonFox', 'Crăiasa', 'Dragon77', 'LunaByte', 'Răzvan_VR', 'StarFighter'];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DisplayDemoSeeder nu poate rula în producție.');
        }
        $players = app(PlayerService::class);
        $sessions = app(GameSessionService::class);
        $arena = Resource::query()->where('type', 'arena')->whereNull('parent_id')->orderBy('id')->firstOrFail();
        $games = Game::query()->whereNotNull('cover_id')->orderBy('sort')->limit(6)->get();

        $created = [];
        foreach (self::NICKNAMES as $i => $nickname) {
            $created[] = Player::query()->where('nickname', $nickname)->first() ?? $players->create([
                'nickname' => $nickname,
                'private_name' => '[DEMO] Jucător '.($i + 1),
                'is_minor' => $i === 4,
                'display_consent' => $i === 7 ? 'refused' : 'granted',
                'guardian' => $i === 4 ? '[DEMO] Părinte' : null,
            ]);
        }

        // istoric: sesiuni finalizate în zilele trecute (egalități incluse), plus una de test și una anulată (nu se numără)
        $plan = [[0, 1, 2, 3, 4, 5], [0, 1, 2, 6], [0, 1, 3, 8], [0, 2, 9, 10], [1, 3, 11], [0, 6, 12], [2, 13, 14], [0, 1]];
        foreach ($plan as $day => $members) {
            $session = $sessions->create(['resource_id' => $arena->id, 'game_id' => $games[$day % max(1, $games->count())]->id ?? null, 'planned_minutes' => 45]);
            foreach ($members as $m) {
                $sessions->addParticipant($session, $created[$m]);
            }
            $sessions->start($session);
            $sessions->finish($session);
            $ended = now()->subDays(8 - $day)->setTime(18, 0);
            $session->forceFill(['started_at' => $ended->copy()->subMinutes(45), 'ended_at' => $ended])->save();
        }
        $test = $sessions->create(['resource_id' => $arena->id, 'is_test' => true]);
        $sessions->addParticipant($test, $created[14]);
        $sessions->start($test);
        $sessions->finish($test);

        // sesiunea live: 8 participanți, unul fără acord de afișare (apare ca „Explorator”)
        $live = $sessions->create(['resource_id' => $arena->id, 'game_id' => $games->first()?->id, 'planned_minutes' => 45]);
        foreach ([0, 1, 3, 5, 7, 9, 11, 13] as $m) {
            $sessions->addParticipant($live, $created[$m]);
        }
        $sessions->start($live);
        $live->forceFill(['started_at' => now()->subMinutes(12)])->save();

        // următoarea sesiune programată (fără participanți afișați)
        $sessions->create(['resource_id' => $arena->id, 'scheduled_at' => now()->addHours(2)->setMinute(30), 'planned_minutes' => 60]);
        $this->command?->info('Date demo pentru ecrane: '.count($created).' jucători, '.GameSession::query()->count().' sesiuni.');
    }
}
