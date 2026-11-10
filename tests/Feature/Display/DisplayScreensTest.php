<?php

namespace Tests\Feature\Display;

use App\Filament\Resources\DisplayScreens\Pages\ListDisplayScreens;
use App\Filament\Resources\GameSessions\Pages\ViewGameSession;
use App\Filament\Resources\GameSessions\RelationManagers\ParticipantsRelationManager;
use App\Models\User;
use App\Modules\Arena\Models\GameSession;
use App\Modules\Arena\Models\Player;
use App\Modules\Arena\Services\GameSessionService;
use App\Modules\Arena\Services\PlayerService;
use App\Modules\Booking\Models\Resource;
use App\Modules\Display\Models\DisplayScreen;
use App\Modules\Display\Services\PairingService;
use Database\Seeders\BaseDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class DisplayScreensTest extends TestCase
{
    use RefreshDatabase;

    private DisplayScreen $live;

    private DisplayScreen $legends;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BaseDataSeeder::class);
        $arena = Resource::query()->create(['name' => 'Arena', 'type' => 'arena', 'capacity' => 8, 'is_active' => true]);
        $this->live = DisplayScreen::query()->where('slug', 'arena-live')->firstOrFail();
        $this->legends = DisplayScreen::query()->where('slug', 'hall-of-legends')->firstOrFail();
        $this->live->forceFill(['resource_id' => $arena->id])->save();
    }

    private function pair(DisplayScreen $screen): string
    {
        $code = app(PairingService::class)->issueCode($screen);

        return $this->postJson("/api/display/{$screen->slug}/activate", ['code' => strtolower($code), 'resolution' => '1920x1080'])->assertOk()->json('token');
    }

    private function state(DisplayScreen $screen, string $token, array $headers = [])
    {
        return $this->getJson("/api/display/{$screen->slug}/state", $headers + ['Authorization' => "Bearer {$token}"]);
    }

    private function liveSession(): GameSession
    {
        $players = app(PlayerService::class);
        $sessions = app(GameSessionService::class);
        $session = $sessions->create(['resource_id' => $this->live->resource_id, 'planned_minutes' => 45]);
        $sessions->addParticipant($session, $players->create(['nickname' => 'NovaRider', 'private_name' => 'Ion Secret', 'display_consent' => 'granted']));
        $sessions->addParticipant($session, $players->create(['nickname' => 'Ascuns', 'private_name' => 'Maria Secret', 'display_consent' => 'refused']));

        return $sessions->start($session);
    }

    public function test_the_display_page_is_a_dataless_noindex_shell(): void
    {
        $this->get('/display/arena-live')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('display-config', false)->assertDontSee('NovaRider')->assertDontSee('cookie', false);
        $this->get('/display/necunoscut')->assertNotFound();
    }

    public function test_activation_codes_are_single_use_and_the_state_needs_the_device_credential(): void
    {
        $this->getJson('/api/display/arena-live/state')->assertUnauthorized();
        $code = app(PairingService::class)->issueCode($this->live);
        $token = $this->postJson('/api/display/arena-live/activate', ['code' => $code])->assertOk()->json('token');
        $this->postJson('/api/display/arena-live/activate', ['code' => $code])->assertStatus(422);
        $this->postJson('/api/display/hall-of-legends/activate', ['code' => $code])->assertStatus(422);

        $this->state($this->live, $token)->assertOk()->assertJsonPath('screen.type', 'live_arena');
        $this->state($this->legends, $token)->assertUnauthorized(); // credențiala e legată de un singur ecran
        $this->assertNotNull($this->live->refresh()->last_seen_at);
        $this->assertNotSame($token, $this->live->device_token_hash);
    }

    public function test_revoking_a_device_cuts_its_access(): void
    {
        $token = $this->pair($this->live);
        app(PairingService::class)->revoke($this->live->refresh());

        $this->state($this->live, $token)->assertUnauthorized();
    }

    public function test_the_live_state_shows_only_the_approved_public_identity(): void
    {
        $token = $this->pair($this->live);
        $this->liveSession();

        $response = $this->state($this->live, $token)->assertOk();
        $players = $response->json('live.session.players');
        $this->assertSame(['NovaRider', 'Explorator 02'], array_column($players, 'name'));
        $this->assertSame('/media/avatars/neutral.svg', $players[1]['avatar']);
        $this->assertSame('live', $response->json('live.session.status'));
        $raw = $response->getContent();
        foreach (['Secret', 'private_name', 'email', 'phone', 'Ascuns'] as $private) {
            $this->assertStringNotContainsString($private, $raw);
        }
    }

    public function test_unchanged_state_returns_304_and_withdrawn_consent_appears_at_the_next_update(): void
    {
        $token = $this->pair($this->live);
        $session = $this->liveSession();
        $first = $this->state($this->live, $token)->assertOk();
        $this->state($this->live, $token, ['If-None-Match' => $first->headers->get('ETag')])->assertStatus(304)->assertHeader('X-Server-Time');

        $player = Player::query()->where('nickname', 'NovaRider')->firstOrFail();
        app(PlayerService::class)->setDisplayConsent($player, 'withdrawn', 'crm');

        $names = array_column($this->state($this->live, $token)->assertOk()->json('live.session.players'), 'name');
        $this->assertSame(['Explorator 01', 'Explorator 02'], $names);
    }

    public function test_finishing_shows_mission_complete_and_feeds_the_hall_of_legends_once(): void
    {
        $liveToken = $this->pair($this->live);
        $legendsToken = $this->pair($this->legends);
        $session = $this->liveSession();
        app(GameSessionService::class)->finish($session);

        $this->state($this->live, $liveToken)->assertOk()->assertJsonPath('live.session', null)->assertJsonPath('live.ended.id', $session->id);
        $rows = $this->state($this->legends, $legendsToken)->assertOk()->json('legends.rows');
        $this->assertSame([['NovaRider', 1, 1]], array_map(fn ($r) => [$r['name'], $r['rank'], $r['sessions']], $rows));
    }

    public function test_quick_commands_expire_and_the_heartbeat_reports_the_player(): void
    {
        $token = $this->pair($this->live);
        $this->live->refresh()->forceFill(['override' => ['command' => 'pause_ads', 'expires_at' => now()->addMinutes(5)->toIso8601ZuluString()]])->save();
        $this->state($this->live, $token)->assertJsonPath('override.command', 'pause_ads');

        $this->travel(6)->minutes();
        $this->postJson('/api/display/arena-live/heartbeat', ['version' => '1.0.0', 'resolution' => '1920x1080', 'uptime' => 3600, 'heap_mb' => 48], ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->state($this->live, $token)->assertJsonPath('override', null);
        $this->assertSame(48, $this->live->refresh()->telemetry['heap_mb']);
        $this->assertTrue($this->live->isOnline());
    }

    public function test_reception_controls_screens_while_only_managers_pair_devices(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel('admin');
        $reception = User::factory()->create(['is_active' => true]);
        $reception->assignRole('reception');
        $this->actingAs($reception);

        $this->get('/admin/display-screens')->assertOk()->assertSee('Live Arena');
        $this->get('/admin/display-playlists')->assertForbidden();
        Livewire::test(ListDisplayScreens::class)
            ->assertActionHidden(TestAction::make('pair')->table($this->live))
            ->callAction(TestAction::make('quick')->table($this->live), ['command' => 'live', 'minutes' => 15]);
        $this->assertSame('live', $this->live->refresh()->override['command']);
        $this->get(route('admin.display.preview', $this->live))->assertOk();
    }

    public function test_crm_pages_for_players_sessions_and_display_open_for_a_manager(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create(['is_active' => true]);
        $manager->assignRole('manager');
        $manager->saveAppAuthenticationSecret((new Google2FA)->generateSecretKey());
        $session = $this->liveSession();
        $this->actingAs($manager);

        $this->get('/admin/players')->assertOk()->assertSee('NovaRider');
        $this->get('/admin/game-sessions')->assertOk();
        $this->get('/admin/game-sessions/'.$session->id)->assertOk()->assertSee('Finalizează');
        Filament::setCurrentPanel('admin');
        Livewire::test(ParticipantsRelationManager::class, ['ownerRecord' => $session, 'pageClass' => ViewGameSession::class])
            ->assertOk()->assertSee('NovaRider')->assertSee('Explorator 02');
        $this->get('/admin/display-playlists/'.$this->live->display_playlist_id.'/edit')->assertOk()->assertSee('Previzualizarea rotației');
        $this->get('/admin/display-assets')->assertOk();
        $this->get('/admin/display-screens/'.$this->live->id.'/edit')->assertOk();
    }
}
