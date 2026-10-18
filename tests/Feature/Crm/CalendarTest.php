<?php

namespace Tests\Feature\Crm;

use App\Domain\Scheduling\AvailabilityService;
use App\Domain\Scheduling\BookingService;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Livewire\Crm\Calendar;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class CalendarTest extends TestCase
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
        $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00', self::TZ));
        $this->location = $this->makeLocation();
        $this->specialist = $this->makeSpecialist(['display_name' => 'Dr. Demo Calendar']);
        $this->service = $this->makeService(['default_duration' => 30, 'content' => ['ro' => ['name' => 'Consultație demo'], 'hu' => ['name' => 'Demo vizsgálat']]]);
        $this->offering = $this->makeOffering($this->location, $this->specialist, $this->service);
        $this->makeSchedule($this->location, $this->specialist, [1 => [['09:00', '12:00']], 2 => [['09:00', '12:00']]]);
    }

    private function reception()
    {
        return $this->staff('reception', [], [$this->location->id]);
    }

    private function calendar($user)
    {
        return Livewire::actingAs($user)->withQueryParams(['date' => '2026-11-02'])->test(Calendar::class);
    }

    private function onlineSlots(): array
    {
        $day = CarbonImmutable::parse('2026-11-02', self::TZ);

        return array_map(fn ($s) => $s['starts_at']->setTimezone(self::TZ)->format('H:i'),
            app(AvailabilityService::class)->slots($this->offering->fresh(), $day, $day, true));
    }

    public function test_page_requires_appointment_permission(): void
    {
        $this->signIn($this->reception())->get('/crm/calendar')->assertOk()->assertSee('Calendar');
        $this->signIn($this->staff('site_editor', ['all_locations' => true]))->get('/crm/calendar')->assertForbidden();
    }

    public function test_day_view_shows_working_time_and_appointments(): void
    {
        app(BookingService::class)->book($this->offering, ['starts_at' => CarbonImmutable::parse('2026-11-02 10:00', self::TZ), 'source' => AppointmentSource::Online, 'contact_name' => 'Pacient Online Demo']);

        $this->calendar($this->reception())
            ->assertSee('Dr. Demo Calendar')
            ->assertSee('Programare nouă la 09:15, Dr. Demo Calendar')
            ->assertSee('Pacient Online Demo')
            ->assertSee('În așteptare');
    }

    public function test_reception_creates_appointment_with_contact_and_it_blocks_online(): void
    {
        $this->calendar($this->reception())
            ->call('startCreate', $this->specialist->id, '2026-11-02', '09:30')
            ->assertSet('panel', 'create')
            ->set('form.contact_name', 'Pacient Telefon Demo')
            ->set('form.contact_phone', '0700 000 000')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSet('panel', 'show');

        $a = Appointment::firstOrFail();
        $this->assertSame(AppointmentStatus::Confirmed, $a->status);
        $this->assertSame(AppointmentSource::Reception, $a->source);
        $this->assertNotContains('09:30', $this->onlineSlots());
    }

    public function test_new_patient_is_registered_and_overlaps_are_refused(): void
    {
        $user = $this->reception();
        $this->calendar($user)
            ->call('startCreate', $this->specialist->id, '2026-11-02', '09:00')
            ->set('newPatient', true)
            ->set('form.last_name', 'Demo')->set('form.first_name', 'Ana')->set('form.phone', '0711111111')
            ->set('form.locale', 'hu')
            ->call('create')
            ->assertHasNoErrors();

        $patient = Patient::firstOrFail();
        $this->assertMatchesRegularExpression('/^P\d{6}$/', $patient->code);
        $this->assertSame('hu', $patient->locale);
        $this->assertSame($patient->id, Appointment::first()->patient_id);

        $this->calendar($user)
            ->call('startCreate', $this->specialist->id, '2026-11-02', '09:15')
            ->call('choosePatient', $patient->id)
            ->call('create')
            ->assertHasErrors('form.time');
        $this->assertSame(1, Appointment::count());
    }

    public function test_patient_search_finds_by_phone_and_name(): void
    {
        Patient::create(['code' => 'P000777', 'first_name' => 'Ilona', 'last_name' => 'Demo', 'phone' => '+40 722 333 444', 'locale' => 'hu']);

        $c = $this->calendar($this->reception())->call('startCreate', $this->specialist->id, '2026-11-02', '09:00');
        $c->set('patientQuery', '0722333')->assertSee('P000777');
        $c->set('patientQuery', 'Demo Ilo')->assertSee('P000777');
    }

    public function test_move_cancel_and_status_from_the_panel(): void
    {
        $a = app(BookingService::class)->book($this->offering, ['starts_at' => CarbonImmutable::parse('2026-11-02 09:00', self::TZ), 'source' => AppointmentSource::Reception]);
        $user = $this->reception();

        // Drag & drop / move form.
        $this->calendar($user)->call('moveTo', $a->id, $this->specialist->id, '2026-11-03', '10:00')->assertHasNoErrors();
        $this->assertSame('2026-11-03 10:00', $a->fresh()->starts_at->setTimezone(self::TZ)->format('Y-m-d H:i'));

        // Stale panel: someone else changed it meanwhile.
        $c = $this->calendar($user)->call('show', $a->id);
        app(BookingService::class)->changeStatus($a->fresh(), AppointmentStatus::Arrived);
        $c->set('reschedule.time', '11:00')->call('applyReschedule')->assertHasErrors('reschedule.time');

        $this->calendar($user)->call('show', $a->id)->call('changeStatus', 'in_consultation')->assertHasNoErrors();
        $this->assertSame(AppointmentStatus::InConsultation, $a->fresh()->status);
    }

    public function test_cancellation_requires_reason_and_suggests_waitlist(): void
    {
        $a = app(BookingService::class)->book($this->offering, ['starts_at' => CarbonImmutable::parse('2026-11-02 09:00', self::TZ), 'source' => AppointmentSource::Reception]);
        $user = $this->reception();
        WaitlistEntry::create(['location_id' => $this->location->id, 'contact_name' => 'Așteaptă Demo', 'contact_phone' => '0733000111', 'preferred_from' => '2026-11-01', 'preferred_to' => '2026-11-10']);

        $this->calendar($user)->call('show', $a->id)
            ->set('cancel.reason', 'other')->set('cancel.note', '')
            ->call('cancelAppointment')->assertHasErrors('cancel.note');

        $this->calendar($user)->call('show', $a->id)
            ->set('cancel.reason', 'patient_request')
            ->call('cancelAppointment')
            ->assertHasNoErrors()
            ->assertSee('Pacienți în așteptare pentru acest interval')
            ->assertSee('Așteaptă Demo')
            ->call('bookFromWaitlist', WaitlistEntry::first()->id)
            ->assertSet('panel', 'create')
            ->assertSet('form.time', '09:00')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame(AppointmentStatus::Cancelled, $a->fresh()->status);
        $this->assertSame('patient_request', $a->fresh()->cancel_reason);
        $this->assertSame(2, Appointment::count());
    }

    public function test_other_locations_are_not_accessible(): void
    {
        $other = $this->makeLocation();
        $sp = $this->makeSpecialist();
        $off = $this->makeOffering($other, $sp, $this->service);
        $this->makeSchedule($other, $sp, [1 => [['09:00', '12:00']]]);
        $foreign = app(BookingService::class)->book($off, ['starts_at' => CarbonImmutable::parse('2026-11-02 09:00', self::TZ), 'source' => AppointmentSource::Reception, 'contact_name' => 'Secret Demo']);

        $c = $this->calendar($this->reception());
        $c->assertSet('locationId', $this->location->id)->assertDontSee('Secret Demo');
        $c->call('show', $foreign->id)->assertForbidden();
        $this->calendar($this->reception())->set('locationId', $other->id)->call('startCreate', $sp->id, '2026-11-02', '09:00')->assertForbidden();
    }

    public function test_week_month_and_agenda_views_render(): void
    {
        app(BookingService::class)->book($this->offering, ['starts_at' => CarbonImmutable::parse('2026-11-03 09:00', self::TZ), 'source' => AppointmentSource::Reception, 'contact_name' => 'Marți Demo']);
        $user = $this->reception();

        $this->calendar($user)->set('view', 'week')->assertSee('Alegeți un specialist')
            ->set('specialistId', $this->specialist->id)->assertSee('Marți Demo');
        $this->calendar($user)->set('view', 'month')->assertSee('1 programare');
        $this->calendar($user)->set('view', 'agenda')->set('date', '2026-11-03')->assertSee('Marți Demo');
        $this->calendar($user)->call('go', 'next')->assertSet('date', '2026-11-03');
    }

    public function test_waitlist_add_and_remove(): void
    {
        $this->calendar($this->reception())
            ->call('openWaitlist')
            ->set('waitlist.contact_name', 'Lista Demo')->set('waitlist.contact_phone', '0744000000')
            ->call('addToWaitlist')
            ->assertHasNoErrors()
            ->assertSee('Lista Demo')
            ->call('removeFromWaitlist', WaitlistEntry::first()->id);

        $this->assertSame('removed', WaitlistEntry::first()->status);
    }
}
