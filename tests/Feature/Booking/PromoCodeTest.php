<?php

namespace Tests\Feature\Booking;

use App\Filament\Resources\PromoCodes\Pages\ManagePromoCodes;
use App\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingData;
use App\Modules\Booking\Services\BookingException;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Catalog\Models\Package;
use App\Modules\Cms\Models\Page;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Marketing\Models\PromoCode;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Database\Seeders\BaseDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\BookingFixtures;
use Tests\TestCase;

class PromoCodeTest extends TestCase
{
    use BookingFixtures, RefreshDatabase;

    private Package $session;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 08:00:00', 'UTC'));
        $this->seed(BaseDataSeeder::class);
        $this->seedSchedule();
        $this->setting('booking.min_lead_minutes', 60);
        $this->session = $this->package($this->arena(), ['price_minor' => 11000]);
        PromoCode::query()->create([
            'code' => 'welcome13', 'name' => 'Oferta de deschidere', 'discount_type' => 'free_participants', 'discount_value' => 1,
            'package_ids' => [$this->session->id], 'redeem_until' => '2026-11-15', 'session_from' => '2026-11-13', 'session_until' => '2026-11-15',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function book(string $date, int $participants = 2, ?Package $package = null, string $email = 'client@example.test', ?string $code = 'Welcome13'): Booking
    {
        return app(BookingService::class)->create(new BookingData(
            package: $package ?? $this->session, startsAt: LocalTime::fromLocal($date, '15:00'), participants: $participants,
            contactName: 'Client Fictiv', contactEmail: $email, contactPhone: null, source: 'reception', promoCode: $code,
        ))['booking'];
    }

    private function refusal(callable $attempt): string
    {
        try {
            $attempt();
        } catch (BookingException $e) {
            $this->assertSame('promo_code', $e->reason);

            return $e->getMessage();
        }
        $this->fail('Codul trebuia refuzat.');
    }

    public function test_the_code_makes_one_session_free_and_the_invoice_shows_it(): void
    {
        $booking = $this->book('2026-11-13');

        $this->assertSame(22000, $booking->total_minor);
        $this->assertSame(11000, $booking->discount_minor);
        $this->assertSame(11000, $booking->balanceMinor());
        $this->assertSame('WELCOME13', $booking->promoCode->code);
        $this->assertSame(1, PromoCode::query()->sole()->activeRedemptions()->count());

        $lines = app(InvoiceService::class)->draftForBooking($booking)->lines;
        $this->assertSame(-11000, (int) $lines->firstWhere('description', 'Reducere cod WELCOME13')->line_gross_minor);
        $this->assertSame(11000, (int) $lines->sum('line_gross_minor'));
    }

    public function test_the_code_is_used_once_per_customer_unless_the_booking_is_cancelled(): void
    {
        $first = $this->book('2026-11-13');

        $this->assertStringContainsString('deja folosit', $this->refusal(fn () => $this->book('2026-11-14')));

        app(BookingService::class)->cancel($first, 'Test', 'user');
        $this->assertSame(11000, $this->book('2026-11-14')->discount_minor);
    }

    public function test_the_code_is_refused_outside_its_package_dates_and_period(): void
    {
        $this->assertStringContainsString('13 noiembrie', $this->refusal(fn () => $this->book('2026-11-20')));
        $this->assertStringContainsString('Sesiune test', $this->refusal(fn () => $this->book('2026-11-13', package: $this->package($this->arena(['name' => 'Arena 2']), ['pricing_model' => 'flat', 'price_minor' => 88000]))));
        $this->refusal(fn () => $this->book('2026-11-13', code: 'NUEXISTA'));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 08:00:00', 'UTC'));
        $this->assertStringContainsString('nu mai este valabil', $this->refusal(fn () => $this->book('2026-11-20')));
        $this->assertSame(0, Booking::query()->count());
    }

    public function test_the_booking_form_previews_the_discount_and_explains_refusals(): void
    {
        $quote = ['package_id' => $this->session->id, 'participants' => 2, 'starts_at' => LocalTime::fromLocal('2026-11-13', '15:00')->toIso8601String()];

        $this->postJson('/api/booking/quote', $quote + ['promo_code' => 'welcome 13'])->assertOk()
            ->assertJsonPath('total', '110 lei')->assertJsonPath('discount.amount', '−110 lei')->assertJsonPath('promo_error', null);
        $this->postJson('/api/booking/quote', $quote + ['promo_code' => 'GRESIT'])->assertOk()
            ->assertJsonPath('total', '220 lei')->assertJsonPath('promo_error', 'Codul nu există sau nu mai este valabil.');
        $this->get('/rezervare?cod=WELCOME13')->assertOk()->assertSee('value="WELCOME13"', false);
    }

    public function test_the_home_page_shows_the_code_only_while_it_can_be_used(): void
    {
        Page::query()->create([
            'key' => 'home', 'template' => 'blocks', 'slug' => ['ro' => ''], 'title' => ['ro' => 'Acasă'],
            'blocks' => ['ro' => [['type' => 'promo_code', 'data' => ['code' => 'WELCOME13', 'title' => 'Prima ta sesiune VR, gratuită.',
                'primary_label' => 'Rezervă acum', 'primary_link' => 'route:booking?pachet=sesiune-vr&cod=WELCOME13']]]],
            'published_locales' => ['ro'], 'status' => 'published', 'published_at' => '2026-10-01 00:00:00',
        ]);

        $this->get('/')->assertOk()->assertSee('Prima ta sesiune VR, gratuită.')->assertSee('Ofertă valabilă până pe 15 noiembrie')
            ->assertSee('/rezervare?pachet=sesiune-vr&amp;cod=WELCOME13', false);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 08:00:00', 'UTC'));
        $this->get('/')->assertOk()->assertDontSee('Prima ta sesiune VR, gratuită.');
    }

    public function test_marketing_sees_the_codes_but_only_price_managers_change_them(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $marketing = User::factory()->create(['is_active' => true]);
        $marketing->assignRole('marketing');
        $marketing->saveAppAuthenticationSecret((new Google2FA)->generateSecretKey());

        $this->actingAs($marketing)->get('/admin/promo-codes')->assertOk()->assertSee('WELCOME13');
        $this->assertFalse($marketing->can('prices.manage'));

        $reception = User::factory()->create(['is_active' => true]);
        $reception->assignRole('reception');
        $this->actingAs($reception)->get('/admin/promo-codes')->assertForbidden();

        $manager = User::factory()->create(['is_active' => true]);
        $manager->assignRole('manager');
        $manager->saveAppAuthenticationSecret((new Google2FA)->generateSecretKey());
        $this->actingAs($manager);
        Filament::setCurrentPanel('admin');
        Livewire::test(ManagePromoCodes::class)
            ->callAction('create', ['code' => 'craciun 25', 'name' => 'Crăciun', 'discount_type' => 'fixed', 'discount_value' => '25,50', 'max_per_customer' => 1, 'is_active' => true])
            ->assertHasNoActionErrors();
        $this->assertSame(2550, PromoCode::query()->where('code', 'CRACIUN25')->sole()->discount_value);
    }
}
