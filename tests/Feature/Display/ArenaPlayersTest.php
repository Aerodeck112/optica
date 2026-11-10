<?php

namespace Tests\Feature\Display;

use App\Modules\Arena\Models\GameSession;
use App\Modules\Arena\Models\Player;
use App\Modules\Arena\Services\GameSessionService;
use App\Modules\Arena\Services\LegendsService;
use App\Modules\Arena\Services\NicknamePolicy;
use App\Modules\Arena\Services\PlayerException;
use App\Modules\Arena\Services\PlayerService;
use App\Modules\Booking\Models\Resource;
use Carbon\CarbonImmutable;
use Database\Seeders\BaseDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ArenaPlayersTest extends TestCase
{
    use RefreshDatabase;

    private PlayerService $players;

    private GameSessionService $sessions;

    private Resource $arena;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BaseDataSeeder::class);
        $this->players = app(PlayerService::class);
        $this->sessions = app(GameSessionService::class);
        $this->arena = Resource::query()->create(['name' => 'Arena', 'type' => 'arena', 'capacity' => 8, 'is_active' => true]);
    }

    private function player(?string $nickname, string $consent = 'granted'): Player
    {
        return $this->players->create(['nickname' => $nickname, 'display_consent' => $consent]);
    }

    /** Sesiune finalizată cu participanții dați; întoarce sesiunea. */
    private function played(array $players, array $overrides = [], ?CarbonImmutable $endedAt = null): GameSession
    {
        $session = $this->sessions->create(['resource_id' => $this->arena->id] + $overrides);
        foreach ($players as $player) {
            $this->sessions->addParticipant($session, $player);
        }
        $this->sessions->start($session);
        $this->sessions->finish($session);
        if ($endedAt) {
            $session->forceFill(['ended_at' => $endedAt])->save();
        }

        return $session;
    }

    public function test_nicknames_are_validated_on_the_server_and_unique_after_normalization(): void
    {
        $policy = app(NicknamePolicy::class);
        $this->player('Ana.Maria');

        $this->assertNotNull($policy->problem('ab'));
        $this->assertNotNull($policy->problem(str_repeat('x', 19)));
        $this->assertNotNull($policy->problem('<script>x'));
        $this->assertNotNull($policy->problem("Nova\u{0007}Rider"));
        $this->assertNotNull($policy->problem('Nova 🚀'));
        $this->assertNotNull($policy->problem('Иван'), 'doar alfabet latin');
        $this->assertNotNull($policy->problem('a..b'));
        $this->assertStringContainsString('deja folosit', (string) $policy->problem('ana maria'));
        $this->assertStringContainsString('deja folosit', (string) $policy->problem('AnaMaria'));
        $this->assertNotNull($policy->problem('PulaMare'));
        $this->assertNotNull($policy->problem('anotherworld1'));
        $this->assertNull($policy->problem('Populara'));
        $this->assertNull($policy->problem('Ștefania_Ț'));
        $this->assertNull($policy->problem('Răzvan VR 7'));
    }

    public function test_renaming_keeps_the_same_player_and_history_and_blocked_names_cannot_be_reused(): void
    {
        $player = $this->player('NovaRider');
        $this->played([$player]);

        $this->players->changeNickname($player, 'NovaRider2', 'staff');
        $this->assertSame(1, Player::query()->count());
        $this->assertSame('NovaRider2', $player->refresh()->nickname);
        $this->assertSame(1, app(LegendsService::class)->ranking()[0]['sessions']);

        $this->players->blockNickname($player, 'nepotrivit');
        $this->assertNull($player->refresh()->nickname);
        $this->assertSame('blocked', $player->nickname_status);
        $this->expectException(PlayerException::class);
        $this->player('nova-rider-2');
    }

    public function test_display_consent_for_a_minor_needs_the_responsible_adult(): void
    {
        $child = $this->players->create(['nickname' => 'Pitic', 'is_minor' => true]);

        try {
            $this->players->setDisplayConsent($child, 'granted', 'reception');
            $this->fail('Acordul unui minor fără adult trebuia refuzat.');
        } catch (PlayerException $e) {
            $this->assertSame('guardian', $e->field);
        }
        $this->players->setDisplayConsent($child, 'granted', 'reception', 'Părinte Test');
        $this->assertTrue($child->refresh()->isPublic());
    }

    public function test_a_session_cannot_start_while_a_participant_has_no_nickname(): void
    {
        $session = $this->sessions->create(['resource_id' => $this->arena->id]);
        $this->sessions->addParticipant($session, $this->player('Ready'));
        $this->sessions->addParticipant($session, $this->player(null));

        try {
            $this->sessions->start($session);
            $this->fail('Pornirea trebuia blocată.');
        } catch (PlayerException $e) {
            $this->assertStringContainsString('locul 2', $e->getMessage());
        }
        $this->assertSame('scheduled', $session->refresh()->status);
    }

    public function test_only_one_running_session_per_arena_and_finishing_twice_is_rejected(): void
    {
        $first = $this->sessions->create(['resource_id' => $this->arena->id]);
        $this->sessions->addParticipant($first, $this->player('One'));
        $this->sessions->start($first);
        $second = $this->sessions->create(['resource_id' => $this->arena->id]);
        $this->sessions->addParticipant($second, $this->player('Two'));

        $this->expectException(PlayerException::class);
        $this->sessions->start($second);
    }

    public function test_legends_count_finished_sessions_once_with_shared_positions_on_ties(): void
    {
        [$a, $b, $c, $hidden] = [$this->player('Alfa'), $this->player('Beta'), $this->player('Gama'), $this->player('Ascuns', 'refused')];
        $this->played([$a, $b, $c, $hidden]);
        $second = $this->played([$a, $b]);
        $this->played([$a, $b], ['is_test' => true]);

        $cancelled = $this->sessions->create(['resource_id' => $this->arena->id]);
        $this->sessions->addParticipant($cancelled, $c);
        $this->sessions->cancel($cancelled, 'test');

        $noShow = $this->sessions->create(['resource_id' => $this->arena->id]);
        $this->sessions->addParticipant($noShow, $c);
        $this->sessions->addParticipant($noShow, $a);
        $this->sessions->correctParticipant($noShow->participants()->where('player_id', $c->id)->first(), ['status' => 'no_show'], null);
        $this->sessions->start($noShow);
        $this->sessions->finish($noShow);

        $ranking = app(LegendsService::class)->ranking();
        $this->assertSame([['Alfa', 1, 3], ['Beta', 2, 2], ['Gama', 3, 1]], array_map(fn ($r) => [$r['nickname'], $r['rank'], $r['sessions']], $ranking));

        // corecție trasabilă: excluderea unei participări scade numărul; Beta ajunge la egalitate cu Gama
        $participant = $second->participants()->where('player_id', $b->id)->first();
        $this->sessions->correctParticipant($participant, ['counts' => false], 'participare înregistrată greșit');
        $ranking = app(LegendsService::class)->ranking();
        $this->assertSame([['Alfa', 1], ['Beta', 2], ['Gama', 2]], array_map(fn ($r) => [$r['nickname'], $r['rank']], $ranking));
        $this->assertDatabaseHas('audit_logs', ['action' => 'game_session.participant_corrected']);
    }

    public function test_periods_filter_the_ranking_and_levels_follow_the_configured_thresholds(): void
    {
        $player = $this->player('Lunar');
        $this->played([$player], [], CarbonImmutable::now()->subMonths(2));
        $this->played([$player]);

        $legends = app(LegendsService::class);
        $month = $legends->period('month');
        $this->assertSame(1, $legends->ranking($month['from'], $month['to'])[0]['sessions']);
        $this->assertSame(2, $legends->ranking()[0]['sessions']);
        $this->assertSame('Recrut', $legends->ranking()[0]['level']);
        $this->assertSame('Din toate timpurile', $legends->period('all')['label']);
    }

    public function test_results_are_validated_and_ties_share_the_same_place(): void
    {
        $session = $this->sessions->create(['resource_id' => $this->arena->id, 'result_type' => 'score_high']);
        foreach (['Unu', 'Doi', 'Trei'] as $nick) {
            $this->sessions->addParticipant($session, $this->player($nick));
        }
        $session = $this->sessions->start($session);
        $ids = $session->participants()->pluck('id')->all();

        try {
            $this->sessions->recordResults($session, [$ids[0] => ['score' => 'abc'], $ids[1] => ['score' => 5], $ids[2] => ['score' => 5]]);
            $this->fail('Scorul invalid trebuia respins.');
        } catch (PlayerException) {
        }
        $this->sessions->recordResults($session, [$ids[0] => ['score' => 300], $ids[1] => ['score' => 500], $ids[2] => ['score' => 500]]);

        $this->assertSame([3, 1, 1], $session->participants()->orderBy('slot')->pluck('place')->all());
        $this->assertSame('manual', $session->refresh()->results_source);
    }

    public function test_retention_anonymizes_players_without_recent_sessions_but_keeps_their_history(): void
    {
        $old = $this->player('VechiulJucator');
        $this->played([$old]);
        $recent = $this->player('Activ');
        DB::table('players')->update(['created_at' => now()->subYears(4)]);
        DB::table('game_sessions')->update(['created_at' => now()->subYears(4), 'started_at' => now()->subYears(4), 'ended_at' => now()->subYears(4)]);
        $this->played([$recent]);

        $this->artisan('aw:retention')->assertSuccessful();

        $this->assertNull($old->refresh()->nickname);
        $this->assertSame(1, $old->participations()->count());
        $this->assertSame('Activ', $recent->refresh()->nickname);
    }
}
