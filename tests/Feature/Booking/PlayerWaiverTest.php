<?php

namespace Tests\Feature\Booking;

use App\Models\User;
use App\Modules\Arena\Models\Player;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\PlayerWaiver;
use App\Modules\Booking\Services\BookingData;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Crm\Models\Consent;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Database\Seeders\BaseDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\BookingFixtures;
use Tests\TestCase;

class PlayerWaiverTest extends TestCase
{
    use BookingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 08:00:00', 'UTC'));
        $this->seed(BaseDataSeeder::class);
        $this->seedSchedule();
        $this->setting('booking.min_lead_minutes', 60);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function booking(int $participants = 2): Booking
    {
        return app(BookingService::class)->create(new BookingData(
            package: $this->package($this->arena()), startsAt: LocalTime::fromLocal('2026-11-22', '15:00'), participants: $participants,
            contactName: 'Client Fictiv', contactEmail: 'client@example.test', contactPhone: '0700 000 000', source: 'reception',
        ))['booking'];
    }

    private function signature(): string
    {
        $image = imagecreatetruecolor(600, 220);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $ink = imagecolorallocate($image, 10, 16, 51);
        imagesetthickness($image, 3);
        for ($x = 20; $x < 560; $x += 6) {
            imageline($image, $x, 110 + (int) (40 * sin($x / 20)), $x + 6, 110 + (int) (40 * sin(($x + 6) / 20)), $ink);
        }
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    private function form(array $overrides = []): array
    {
        return $overrides + [
            'participant_name' => 'Ion Testescu', 'birth_date' => '1990-05-12', 'signer_name' => 'Ion Testescu',
            'signature' => $this->signature(), 'accept' => '1', '_started' => time() - 60,
        ];
    }

    public function test_players_sign_from_the_booking_link_and_the_exact_text_version_is_kept(): void
    {
        $booking = $this->booking();
        $url = $booking->waiverUrl();

        $this->get($url)->assertOk()->assertSee('Declarație de informare')->assertSee('0 din 2 declarații');
        $this->get(strtok($url, '?'))->assertForbidden();

        $this->post($url, $this->form())->assertRedirect()->assertSessionHas('waiver_signed', 'Ion Testescu');

        $waiver = PlayerWaiver::query()->sole();
        $this->assertSame($booking->id, $waiver->booking_id);
        $this->assertSame('1.0', $waiver->document_version);
        $this->assertSame($waiver->document->content_hash, $waiver->document_hash);
        $this->assertSame('self', $waiver->signer_role);
        $this->assertSame('online', $waiver->source);
        $this->assertStringNotContainsString('1990-05-12', (string) DB::table('player_waivers')->value('participant_birth_date'));
        $this->get($url)->assertSee('1 din 2 declarații');
    }

    public function test_a_parent_signs_for_a_minor_and_image_consent_is_not_taken_online(): void
    {
        $url = $this->booking()->waiverUrl();

        $this->post($url, $this->form(['participant_name' => 'Maria Testescu', 'birth_date' => '2015-03-02']))->assertSessionHasErrors('guardian_name');
        $this->assertSame(0, PlayerWaiver::query()->count());

        $this->post($url, $this->form(['participant_name' => 'Maria Testescu', 'birth_date' => '2015-03-02', 'guardian_name' => 'Ana Testescu',
            'guardian_phone' => '0740 000 000', 'signer_name' => 'Ana Testescu', 'photo_consent' => '1']))->assertSessionHas('waiver_signed');

        $waiver = PlayerWaiver::query()->sole();
        $this->assertTrue($waiver->is_minor);
        $this->assertSame('guardian', $waiver->signer_role);
        $this->assertSame('0740 000 000', $waiver->guardian_phone);
        $this->assertFalse($waiver->photo_consent);
    }

    public function test_reception_form_rejects_blank_signatures_and_unknown_booking_codes(): void
    {
        $this->post('/declaratie', $this->form(['signature' => 'data:image/png;base64,'.base64_encode('x')]))->assertSessionHasErrors('signature');
        $this->post('/declaratie', $this->form(['booking_code' => 'NUEXISTA1']))->assertSessionHasErrors('booking_code');
        $this->post('/declaratie', $this->form(['accept' => null]))->assertSessionHasErrors('accept');
        $this->assertSame(0, PlayerWaiver::query()->count());

        $this->post('/declaratie', $this->form(['email' => 'ion@example.test', 'marketing_email' => '1']))->assertSessionHas('waiver_signed');
        $this->assertSame('reception', PlayerWaiver::query()->sole()->source);
        $this->assertSame(1, Consent::query()->where('channel', 'email')->where('status', 'granted')->where('source', 'waiver')->count());
    }

    public function test_reception_sees_and_validates_waivers_while_game_operators_only_see_the_count(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $booking = $this->booking();
        $this->post($booking->waiverUrl(), $this->form());
        $waiver = PlayerWaiver::query()->sole();

        $reception = User::factory()->create(['is_active' => true]);
        $reception->assignRole('reception');
        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole('operator');

        $this->actingAs($reception)->get('/admin/player-waivers')->assertOk()->assertSee('Ion Testescu');
        $this->actingAs($reception)->get('/admin/player-waivers/'.$waiver->id)->assertOk()->assertSee('1990');
        $this->actingAs($reception)->get(route('admin.waivers.print', $waiver))->assertOk()->assertSee('Ion Testescu');
        $this->actingAs($operator)->get('/admin/player-waivers')->assertForbidden();
        $this->actingAs($operator)->get(route('admin.waivers.print', $waiver))->assertForbidden();
        $this->actingAs($operator)->get('/admin/check-in')->assertOk()->assertDontSee('Ion Testescu');

        $owner = User::factory()->create(['is_active' => true]);
        $owner->assignRole('super_admin');
        $owner->saveAppAuthenticationSecret((new Google2FA)->generateSecretKey());
        $this->actingAs($owner)->get(route('admin.waivers.qr'))->assertOk()->assertSee('/declaratie', false)->assertSee('/en/player-agreement', false);
    }

    public function test_retention_removes_waivers_after_the_configured_period(): void
    {
        $this->post('/declaratie', $this->form());
        $this->setting('retention.waivers_months', 36);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2029-12-01 08:00:00', 'UTC'));

        $this->artisan('aw:retention')->assertSuccessful();

        $this->assertSame(0, PlayerWaiver::query()->count());
    }

    public function test_the_waiver_creates_one_lasting_player_profile_with_a_separate_screen_consent(): void
    {
        $url = $this->booking()->waiverUrl();

        $this->post($url, $this->form(['nickname' => 'Ion<script>', 'screen_display' => '1']))->assertSessionHasErrors('nickname');
        $this->post($url, $this->form(['nickname' => 'IonVR', 'avatar_key' => 'robot-03', 'screen_display' => '1']))->assertSessionHas('waiver_signed');

        $player = Player::query()->sole();
        $this->assertSame(['IonVR', 'review', 'robot-03', 'granted'], [$player->nickname, $player->nickname_status, $player->avatar_key, $player->display_consent]);
        $this->assertFalse($player->isPublic(), 'nickname-ul introdus online se verifică la recepție');
        $this->assertSame($player->id, PlayerWaiver::query()->sole()->player_id);

        // aceeași persoană revine: același profil, iar acordul urmează ultima declarație
        $this->post($url, $this->form(['nickname' => 'AltNume']))->assertSessionHas('waiver_signed');
        $this->assertSame(1, Player::query()->count());
        $this->assertSame(['IonVR', 'refused'], [$player->refresh()->nickname, $player->display_consent]);
    }
}
