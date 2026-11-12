<?php

namespace Tests\Feature\Booking;

use App\Filament\Pages\CheckIn;
use App\Filament\Resources\PlayerWaivers\Widgets\MissingWaivers;
use App\Http\Controllers\Site\WaiverTabletController;
use App\Models\User;
use App\Modules\Arena\Models\SessionParticipant;
use App\Modules\Arena\Services\GameSessionService;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\PlayerWaiver;
use App\Modules\Booking\Models\WaiverCard;
use App\Modules\Booking\Models\WaiverCode;
use App\Modules\Booking\Services\BookingData;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Booking\Services\PlayerWaiverService;
use App\Modules\Booking\Services\WaiverCodeException;
use App\Modules\Booking\Services\WaiverCodeService;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Database\Seeders\BaseDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BookingFixtures;
use Tests\TestCase;

class WaiverTabletTest extends TestCase
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
            package: $this->package($this->arena()), startsAt: LocalTime::fromLocal('2026-11-20', '15:00'), participants: $participants,
            contactName: 'Client Fictiv', contactEmail: 'client@example.test', contactPhone: '0700 000 000', source: 'reception',
        ))['booking'];
    }

    private function form(array $overrides = []): array
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

        return $overrides + [
            'participant_name' => 'Ion Testescu', 'birth_date' => '1990-05-12', 'signer_name' => 'Ion Testescu',
            'signature' => 'data:image/png;base64,'.base64_encode((string) ob_get_clean()), 'accept' => '1', '_started' => time() - 60,
        ];
    }

    public function test_each_player_signs_on_the_tablet_with_a_one_time_code_linked_to_the_booking(): void
    {
        $booking = $this->booking();
        [$first, $second] = app(WaiverCodeService::class)->issue($booking, 2)->map(fn (WaiverCode $c) => $c->code)->all();

        $this->get('/declaratie/tableta')->assertOk()->assertSee('codul de 6 cifre')->assertHeader('Cache-Control', 'no-store, private')
            ->assertDontSee('chat-launch', false)->assertSee('noindex', false);

        $this->post('/declaratie/tableta', ['code' => substr($first, 0, 3).' '.substr($first, 3)])->assertRedirect('/declaratie/tableta/semnare');
        $this->get('/declaratie/tableta/semnare')->assertOk()->assertSee($booking->code)->assertDontSee('name="booking_code"', false);
        $this->post('/declaratie/tableta/semnare', $this->form())->assertRedirect('/declaratie/tableta')->assertSessionHas('waiver_signed', 'Ion Testescu');

        $waiver = PlayerWaiver::query()->sole();
        $this->assertSame(['tablet', $booking->id], [$waiver->source, $waiver->booking_id]);
        $this->assertSame($waiver->id, WaiverCode::query()->where('position', 1)->sole()->player_waiver_id);

        // după semnare tableta e goală: formularul nu mai se deschide, iar același cod nu mai merge
        $this->get('/declaratie/tableta/semnare')->assertRedirect('/declaratie/tableta');
        $this->post('/declaratie/tableta', ['code' => $first])->assertRedirect('/declaratie/tableta')->assertSessionHasErrors('code');

        $this->post('/declaratie/tableta', ['code' => $second]);
        $this->post('/declaratie/tableta/semnare', $this->form(['participant_name' => 'Ana Testescu', 'signer_name' => 'Ana Testescu']))->assertSessionHas('waiver_signed');
        $this->assertSame(['signed' => 2, 'expected' => 2, 'complete' => true], app(PlayerWaiverService::class)->progress($booking));
    }

    public function test_wrong_expired_and_stale_codes_do_not_open_the_form_and_guessing_is_rate_limited(): void
    {
        $booking = $this->booking();
        $code = app(WaiverCodeService::class)->issue($booking, 1)->sole()->code;

        $this->post('/declaratie/tableta', ['code' => '12345'])->assertSessionHasErrors('code');
        $this->get('/declaratie/tableta/semnare')->assertRedirect('/declaratie/tableta');

        // formularul lăsat deschis prea mult se închide, chiar dacă codul mai e valabil
        $this->post('/declaratie/tableta', ['code' => $code])->assertRedirect('/declaratie/tableta/semnare');
        $this->travel(WaiverTabletController::FORM_MINUTES + 1)->minutes();
        $this->post('/declaratie/tableta/semnare', $this->form())->assertRedirect('/declaratie/tableta')->assertSessionHasErrors('code');
        $this->assertSame(0, PlayerWaiver::query()->count());

        $this->travelTo(CarbonImmutable::parse('2026-11-20 17:00:00', 'UTC'));
        $this->post('/declaratie/tableta', ['code' => $code])->assertSessionHasErrors('code');

        for ($i = 0; $i < 19; $i++) {
            $this->post('/declaratie/tableta', ['code' => sprintf('%06d', 100000 + $i)]);
        }
        $this->post('/declaratie/tableta', ['code' => '999999'])->assertStatus(429);
    }

    public function test_a_player_who_signs_on_the_tablet_joins_the_open_game_session_with_the_chosen_nickname(): void
    {
        $booking = $this->booking();
        $session = app(GameSessionService::class)->forBooking($booking);
        $code = app(WaiverCodeService::class)->issue($booking, 1)->sole()->code;

        $this->post('/declaratie/tableta', ['code' => $code]);
        $this->post('/declaratie/tableta/semnare', $this->form(['nickname' => 'IonVR', 'avatar_key' => 'robot-03', 'screen_display' => '1']))->assertSessionHas('waiver_signed');

        $participant = SessionParticipant::query()->where('game_session_id', $session->id)->sole();
        $this->assertSame(['IonVR', 'robot-03'], [$participant->player->nickname, $participant->player->avatar_key]);
    }

    public function test_check_in_hands_out_free_cards_whose_printed_code_works_once_per_assignment(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $booking = $this->booking(3);
        $reception = User::factory()->create(['is_active' => true]);
        $reception->assignRole('reception');
        WaiverCard::query()->where('number', 2)->update(['is_active' => false]);

        $this->actingAs($reception);
        Livewire::test(CheckIn::class)->call('issueCodes', $booking->id);
        $codes = WaiverCode::query()->with('card')->orderBy('position')->get();
        $this->assertSame([1, 3, 4], $codes->pluck('card.number')->all(), 'cardul scos din uz nu se dă');
        $this->assertDatabaseMissing('waiver_codes', ['code' => $codes[0]->code]);
        Livewire::test(CheckIn::class)->call('issueCodes', $booking->id);
        $this->assertSame(3, WaiverCode::query()->count(), 'al doilea clic nu dublează');

        // jucătorul tastează (sau scanează) codul tipărit pe card; după semnare cardul e din nou liber
        $card = WaiverCard::query()->where('number', 1)->sole();
        $this->post('/declaratie/tableta', ['code' => $card->code])->assertRedirect('/declaratie/tableta/semnare');
        $this->post('/declaratie/tableta/semnare', $this->form())->assertSessionHas('waiver_signed');
        $this->post('/declaratie/tableta', ['code' => $card->code])->assertSessionHasErrors('code');
        $this->assertTrue(app(WaiverCodeService::class)->freeCards()->contains('number', 1));

        // un card aflat la alt grup nu se poate da; după „ia înapoi” e liber
        $other = $this->booking(1);
        $this->assertThrows(fn () => app(WaiverCodeService::class)->assignCard('AWC-003', $other), WaiverCodeException::class, 'deja dat altui grup ('.$booking->code.')');
        Livewire::test(CheckIn::class)->call('releaseCards', $booking->id);
        $this->assertSame('AWC-003', app(WaiverCodeService::class)->assignCard(WaiverCard::query()->where('number', 3)->value('code'), $other)->card->serial);
    }

    public function test_reception_prints_slips_for_extra_players_while_other_roles_cannot(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $booking = $this->booking(3);
        $reception = User::factory()->create(['is_active' => true]);
        $reception->assignRole('reception');
        $marketing = User::factory()->create(['is_active' => true]);
        $marketing->assignRole('marketing');

        $codes = app(WaiverCodeService::class)->issue($booking, 3);
        $this->assertSame(3, $codes->pluck('code_hash')->unique()->count());
        $this->assertTrue($codes->every(fn (WaiverCode $c) => ! WaiverCard::query()->where('code', $c->code)->exists()), 'bilețelele nu folosesc codurile cardurilor');

        $this->actingAs($reception)->get(route('admin.waivers.codes', ['booking' => $booking->id]))->assertOk()->assertSee($codes[2]->formatted());
        $this->actingAs($marketing)->get(route('admin.waivers.codes', ['booking' => $booking->id]))->assertForbidden();
    }

    public function test_the_waivers_page_lists_today_unsigned_bookings_and_gives_a_code_to_one_person_at_a_time(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $booking = $this->booking(2);
        $done = $this->booking(1);
        app(WaiverCodeService::class)->redeem(app(WaiverCodeService::class)->issue($done, 1)->sole(),
            fn (?Booking $b) => app(PlayerWaiverService::class)->sign($this->form() + ['birth_date' => '1990-05-12'], $b));
        $reception = User::factory()->create(['is_active' => true]);
        $reception->assignRole('reception');
        $this->actingAs($reception);

        $this->get('/admin/player-waivers')->assertOk()->assertSeeLivewire(MissingWaivers::class);
        Livewire::test(MissingWaivers::class)
            ->assertCanSeeTableRecords([$booking])->assertCanNotSeeTableRecords([$done])
            ->callAction(TestAction::make('one')->table($booking))
            ->assertNotified();

        $code = WaiverCode::query()->where('booking_id', $booking->id)->with('card')->sole();
        $this->assertSame(1, $code->card->number, 'întâi se dă un card liber');

        Livewire::test(MissingWaivers::class)->callAction(TestAction::make('walkIn')->table());
        $this->assertSame(1, WaiverCode::query()->whereNull('booking_id')->count());
    }
}
