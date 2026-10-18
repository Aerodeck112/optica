<?php

namespace Tests\Feature\Site;

use App\Domain\Scheduling\AvailabilityService;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Livewire\Site\BookingWizard;
use App\Livewire\Site\ManageBooking;
use App\Models\Appointment;
use App\Models\Consent;
use App\Models\LocationSpecialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class OnlineBookingTest extends TestCase
{
    use RefreshDatabase, SchedulingFixtures;

    private const TZ = 'Europe/Bucharest';

    private $location;

    private $specialist;

    private $service;

    private $offering;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('booking-hold:127.0.0.1');
        RateLimiter::clear('booking-submit:127.0.0.1');
        $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00', self::TZ));
        $this->location = $this->makeLocation(['code' => 'demo-loc']);
        $this->specialist = $this->makeSpecialist(['display_name' => 'Dr. Online Demo']);
        $this->service = $this->makeService(['default_duration' => 30, 'content' => ['ro' => ['name' => 'Consultație online demo'], 'hu' => ['name' => 'Online demo vizsgálat']]]);
        $this->offering = $this->makeOffering($this->location, $this->specialist, $this->service);
        $this->makeSchedule($this->location, $this->specialist, [1 => [['09:00', '11:00']], 2 => [['09:00', '11:00']]]);
    }

    private function utc(string $local): string
    {
        return CarbonImmutable::parse($local, self::TZ)->utc()->toIso8601String();
    }

    private function wizardAtTimeStep()
    {
        return Livewire::test(BookingWizard::class)
            ->call('chooseLocation', 'demo-loc')
            ->call('chooseService', $this->service->id)
            ->call('chooseSpecialist', 'first');
    }

    private function onlineSlots(string $date = '2026-11-02'): array
    {
        $day = CarbonImmutable::parse($date, self::TZ);

        return array_map(fn ($s) => $s['starts_at']->setTimezone(self::TZ)->format('H:i'),
            app(AvailabilityService::class)->slots($this->offering->fresh(), $day, $day, true));
    }

    public function test_pages_answer_in_both_languages(): void
    {
        $this->get('/programare')->assertOk()->assertSee('Programare online')->assertSee('hreflang="hu"', false);
        $this->get('/hu/programare')->assertOk()->assertSee('Online időpontfoglalás')->assertSee('<html lang="hu">', false);
        $this->get('/programare?locatie=demo-loc')->assertOk()->assertSee('Pentru ce doriți programarea?');
    }

    /** T01: a booking from the site appears in the CRM as a pending online request. */
    public function test_full_flow_creates_pending_request_with_consents_and_manage_link(): void
    {
        $component = $this->wizardAtTimeStep()
            ->assertSee('09:00')
            ->call('chooseSlot', $this->offering->id, $this->utc('2026-11-02 09:30'))
            ->assertSet('step', 5)
            ->set('name', 'Pacient Online Demo')
            ->set('phone', '0712 345 678')
            ->set('email', 'demo@example.test')
            ->set('patientLocale', 'hu')
            ->set('privacy', true)
            ->set('marketing.whatsapp', true)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('step', 6)
            ->assertSee('Am primit cererea de programare');

        $a = Appointment::firstOrFail();
        $this->assertSame(AppointmentStatus::Pending, $a->status);
        $this->assertSame(AppointmentSource::Online, $a->source);
        $this->assertSame('hu', $a->locale);
        $this->assertSame('Pacient Online Demo', $a->contact_name);
        $this->assertNotContains('09:30', $this->onlineSlots());

        $this->assertSame(1, Consent::where(['purpose' => 'privacy_notice', 'granted' => true])->count());
        $this->assertTrue(Consent::where(['purpose' => 'marketing', 'channel' => 'whatsapp'])->value('granted'));
        $this->assertFalse(Consent::where(['purpose' => 'marketing', 'channel' => 'email'])->value('granted'));

        $url = $component->get('manageUrl');
        $this->assertStringContainsString('/hu/programare/gestionare/', $url);
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertSee('Online demo vizsgálat');
    }

    public function test_automatic_confirmation_and_privacy_required(): void
    {
        $this->location->update(['confirmation_mode' => 'automatic']);
        $c = $this->wizardAtTimeStep()->call('chooseSlot', $this->offering->id, $this->utc('2026-11-02 09:00'))
            ->set('name', 'Pacient Demo')->set('phone', '0712345678')
            ->call('submit')->assertHasErrors('privacy');
        $c->set('privacy', true)->call('submit')->assertHasNoErrors()->assertSee('Programarea este confirmată');
        $this->assertSame(AppointmentStatus::Confirmed, Appointment::first()->status);
    }

    public function test_hold_blocks_others_and_changing_slot_releases_it(): void
    {
        $first = $this->wizardAtTimeStep()->call('chooseSlot', $this->offering->id, $this->utc('2026-11-02 10:00'));
        $this->assertNotContains('10:00', $this->onlineSlots());

        // Another visitor cannot take it.
        $this->wizardAtTimeStep()->call('chooseSlot', $this->offering->id, $this->utc('2026-11-02 10:00'))->assertHasErrors('slot');

        // The first visitor goes back and picks another time: 10:00 is free again.
        $first->call('back')->call('chooseSlot', $this->offering->id, $this->utc('2026-11-02 10:30'));
        $this->assertContains('10:00', $this->onlineSlots());
        $this->assertNotContains('10:30', $this->onlineSlots());
    }

    public function test_hold_expiry_before_submit_is_reported(): void
    {
        $c = $this->wizardAtTimeStep()->call('chooseSlot', $this->offering->id, $this->utc('2026-11-02 09:00'));
        $this->travel(11)->minutes();
        $this->artisan('bookings:expire');

        $c->set('name', 'Pacient Demo')->set('phone', '0712345678')->set('privacy', true)
            ->call('submit')->assertHasErrors('form')->assertSet('step', 4);
        $this->assertSame(0, Appointment::whereIn('status', ['pending', 'confirmed'])->count());
    }

    public function test_honeypot_does_not_book(): void
    {
        $this->wizardAtTimeStep()->call('chooseSlot', $this->offering->id, $this->utc('2026-11-02 09:00'))
            ->set('name', 'Bot')->set('phone', '0712345678')->set('privacy', true)->set('website', 'http://spam')
            ->call('submit')->assertSet('step', 6);
        $this->assertSame(0, Appointment::whereIn('status', ['pending', 'confirmed'])->count());
        $this->assertContains('09:00', $this->onlineSlots());
    }

    public function test_paused_specialist_shows_message_and_has_no_slots(): void
    {
        LocationSpecialist::query()->update(['online_paused' => true, 'content' => ['ro' => ['pause_message' => 'Revin din 10 noiembrie.']]]);

        Livewire::test(BookingWizard::class)->call('chooseLocation', 'demo-loc')->assertSee('nu se pot face momentan programări online');
        $this->assertSame([], $this->onlineSlots());
    }

    public function test_manage_link_cancels_and_reschedules_and_rejects_bad_tokens(): void
    {
        $url = $this->wizardAtTimeStep()->call('chooseSlot', $this->offering->id, $this->utc('2026-11-02 09:00'))
            ->set('name', 'Pacient Demo')->set('phone', '0712345678')->set('privacy', true)
            ->call('submit')->get('manageUrl');
        $token = basename(parse_url($url, PHP_URL_PATH));

        $this->get('/programare/gestionare/'.str_repeat('a', 48))->assertNotFound();
        $this->get('/programare/gestionare/x')->assertNotFound();

        Livewire::test(ManageBooking::class, ['token' => $token])
            ->call('reschedule', $this->utc('2026-11-03 10:00'))
            ->assertHasNoErrors()
            ->assertSee('Programarea a fost mutată');
        $this->assertSame('2026-11-03 10:00', Appointment::first()->starts_at->setTimezone(self::TZ)->format('Y-m-d H:i'));

        Livewire::test(ManageBooking::class, ['token' => $token])->call('cancel')->assertSee('Programarea a fost anulată');
        $this->assertSame(AppointmentStatus::Cancelled, Appointment::first()->status);
        $this->assertContains('09:00', $this->onlineSlots());
        // A cancelled appointment can no longer be changed through the link.
        Livewire::test(ManageBooking::class, ['token' => $token])->call('cancel')->assertForbidden();
    }

    public function test_public_api_returns_only_times(): void
    {
        $this->wizardAtTimeStep()->call('chooseSlot', $this->offering->id, $this->utc('2026-11-02 09:00'))
            ->set('name', 'Secret Pacient')->set('phone', '0712345678')->set('privacy', true)->call('submit');

        $response = $this->getJson('/api/disponibilitate?service='.$this->service->id.'&from=2026-11-02&days=1')->assertOk();
        $starts = collect($response->json('data'))->pluck('start')->map(fn ($s) => substr($s, 11, 5))->all();
        $this->assertNotContains('09:00', $starts);
        $this->assertContains('09:30', $starts);
        $this->assertStringNotContainsString('Secret', $response->getContent());
        $this->assertSame(['start', 'end', 'location', 'specialist'], array_keys($response->json('data.0')));
    }
}
