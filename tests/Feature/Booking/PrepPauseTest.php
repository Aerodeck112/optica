<?php

namespace Tests\Feature\Booking;

use App\Filament\Pages\Calendar;
use App\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\AvailabilityService;
use App\Modules\Booking\Services\BookingData;
use App\Modules\Booking\Services\BookingException;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Catalog\Models\Package;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Database\Seeders\BaseDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BookingFixtures;
use Tests\TestCase;

class PrepPauseTest extends TestCase
{
    use BookingFixtures, RefreshDatabase;

    private Package $session;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC'));
        $this->seed(BaseDataSeeder::class);
        $this->seedSchedule();
        $this->setting('booking.min_lead_minutes', 60);
        $this->setting('booking.slot_interval_minutes', 30);
        $this->session = $this->package($this->arena(), ['buffer_after_minutes' => 0]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function book(string $time, bool $staff = false): Booking
    {
        return app(BookingService::class)->create(new BookingData(
            package: $this->session, startsAt: LocalTime::fromLocal('2026-11-21', $time), participants: 2,
            contactName: 'Client Fictiv', contactEmail: 'client'.uniqid().'@example.test', contactPhone: null, source: $staff ? 'reception' : 'web',
        ))['booking'];
    }

    /** @return array<string, bool> ora locală => liberă */
    private function slots(): array
    {
        $slots = app(AvailabilityService::class)->slotsFor($this->session, '2026-11-21', 2)['slots'];

        return collect($slots)->mapWithKeys(fn (array $slot) => [$slot['local_time'] => $slot['available']])->all();
    }

    public function test_after_each_round_the_arena_is_blocked_for_the_prep_pause_and_the_next_round_starts_right_after_it(): void
    {
        $this->book('10:00');
        $slots = $this->slots();

        $this->assertFalse($slots['11:00'], '11:00 cade în pauza de pregătire');
        $this->assertTrue($slots['11:20'], 'runda următoare începe imediat după pauză');
        $this->assertTrue($slots['11:30']);
        $this->assertFalse($slots['09:30'] ?? false);

        $this->assertSame('11:20', LocalTime::toLocal($this->book('11:20')->starts_at)->format('H:i'));
        $this->assertFalse($this->slots()['12:30'], '12:20–12:40 este pauza celei de-a doua runde');
        $this->assertTrue($this->slots()['12:40']);
    }

    public function test_reception_cannot_book_inside_the_pause_and_the_setting_can_change_or_disable_it(): void
    {
        $this->book('10:00');
        $this->expectReason('slot_unavailable', fn () => $this->book('11:00', staff: true));

        // pauza oprită din setări: rezervările făcute de acum nu mai blochează timp după joc
        $this->setting('booking.prep_minutes', 0);
        $this->book('13:00');
        $this->assertTrue($this->slots()['14:00'], 'fără pauză, runda următoare poate începe imediat');
        $this->assertFalse($this->slots()['11:00'], 'rezervarea făcută cu pauză își păstrează pauza');

        $this->setting('booking.prep_minutes', 30);
        $this->session->forceFill(['buffer_after_minutes' => 45])->save();
        $this->assertSame(45, $this->session->fresh()->bufferAfterMinutes(), 'o pauză mai mare a pachetului are prioritate');
    }

    public function test_the_day_calendar_shows_the_prep_pause_and_flags_real_overlaps_side_by_side(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $reception = User::factory()->create(['is_active' => true]);
        $reception->assignRole('reception');
        $this->book('10:00');
        $this->book('11:20');

        $calendar = Livewire::actingAs($reception)->test(Calendar::class)->set('mode', 'day')->set('date', '2026-11-21');
        $calendar->assertSee('Pregătire arenă · 20 min (până la 11:20)')->assertDontSee('Suprapunere');

        // o rezervare veche, făcută înainte de pauză, care intră peste runda următoare
        $old = $this->book('14:00');
        $old->occupancies()->update(['starts_at' => LocalTime::fromLocal('2026-11-21', '12:00'), 'ends_at' => LocalTime::fromLocal('2026-11-21', '13:00')]);
        $calendar->call('$refresh')->assertSee('Suprapunere — verificați orele');
    }

    private function expectReason(string $reason, callable $attempt): void
    {
        try {
            $attempt();
        } catch (BookingException $e) {
            $this->assertSame($reason, $e->reason);

            return;
        }
        $this->fail('Rezervarea trebuia refuzată.');
    }
}
