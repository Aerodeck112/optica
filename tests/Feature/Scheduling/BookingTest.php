<?php

namespace Tests\Feature\Scheduling;

use App\Domain\Scheduling\AvailabilityService;
use App\Domain\Scheduling\BookingException;
use App\Domain\Scheduling\BookingService;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Resource;
use App\Models\ScheduleException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase, SchedulingFixtures;

    private const TZ = 'Europe/Bucharest';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00', self::TZ));
    }

    private function at(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, self::TZ);
    }

    private function booking(): BookingService
    {
        return app(BookingService::class);
    }

    private function onlineSlots($offering, string $date = '2026-11-02'): array
    {
        $day = $this->at($date);

        return array_map(
            fn ($s) => $s['starts_at']->setTimezone(self::TZ)->format('H:i'),
            app(AvailabilityService::class)->slots($offering, $day, $day, online: true),
        );
    }

    private function basicSetup(int $duration = 30, array $offering = []): array
    {
        $location = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $service = $this->makeService(['default_duration' => $duration]);
        $offeringModel = $this->makeOffering($location, $specialist, $service, $offering);
        $this->makeSchedule($location, $specialist, [1 => [['09:00', '11:00']]]);

        return [$location, $specialist, $service, $offeringModel];
    }

    /** T01: online booking is visible in the CRM; a reception booking blocks the slot online. */
    public function test_online_and_reception_bookings_share_availability(): void
    {
        [, , , $offering] = $this->basicSetup();

        $online = $this->booking()->book($offering, [
            'starts_at' => $this->at('2026-11-02 09:00'),
            'source' => AppointmentSource::Online,
            'contact_name' => 'Pacient Demo',
            'contact_phone' => '0700000000',
            'locale' => 'hu',
        ]);
        $this->assertSame(AppointmentStatus::Pending, $online->status);
        $this->assertSame('hu', $online->locale);
        // CRM reads the same table: the online request is there.
        $this->assertTrue(Appointment::query()->whereKey($online->id)->where('source', 'online')->exists());

        $this->booking()->book($offering, [
            'starts_at' => $this->at('2026-11-02 10:00'),
            'source' => AppointmentSource::Reception,
        ]);

        $slots = $this->onlineSlots($offering);
        $this->assertNotContains('09:00', $slots);
        $this->assertNotContains('09:15', $slots); // would overlap 09:00–09:30
        $this->assertNotContains('10:00', $slots);
        $this->assertNotContains('09:45', $slots); // would overlap 10:00–10:30
        $this->assertContains('09:30', $slots);
        $this->assertContains('10:30', $slots);
    }

    public function test_cancellation_releases_the_slot(): void
    {
        [, , , $offering] = $this->basicSetup();
        $appointment = $this->booking()->book($offering, ['starts_at' => $this->at('2026-11-02 09:00'), 'source' => AppointmentSource::Reception]);
        $this->assertNotContains('09:00', $this->onlineSlots($offering));

        $this->booking()->cancel($appointment, 'patient_request', note: 'telefonic');

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::Cancelled, $appointment->status);
        $this->assertSame('patient_request', $appointment->cancel_reason);
        $this->assertContains('09:00', $this->onlineSlots($offering));
        $this->assertDatabaseHas('appointment_events', ['appointment_id' => $appointment->id, 'type' => 'cancelled', 'from_status' => 'confirmed', 'to_status' => 'cancelled']);
    }

    public function test_overlapping_booking_is_rejected(): void
    {
        [, , , $offering] = $this->basicSetup();
        $this->booking()->book($offering, ['starts_at' => $this->at('2026-11-02 09:00'), 'source' => AppointmentSource::Reception]);

        $this->expectExceptionObject(new BookingException(BookingException::SPECIALIST_BUSY));
        $this->booking()->book($offering, ['starts_at' => $this->at('2026-11-02 09:15'), 'source' => AppointmentSource::Reception]);
    }

    /** No overlap for the same specialist across locations, including travel time (T02). */
    public function test_specialist_cannot_be_in_two_locations(): void
    {
        $locationA = $this->makeLocation();
        $locationB = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $service = $this->makeService(['default_duration' => 30]);
        $offeringA = $this->makeOffering($locationA, $specialist, $service);
        $offeringB = $this->makeOffering($locationB, $specialist, $service);
        $this->makeSchedule($locationA, $specialist, [1 => [['09:00', '12:00']]]);
        $this->makeSchedule($locationB, $specialist, [1 => [['09:00', '12:00']]]);
        DB::table('location_travel_times')->insert(['from_location_id' => $locationA->id, 'to_location_id' => $locationB->id, 'minutes' => 20]);

        $this->booking()->book($offeringA, ['starts_at' => $this->at('2026-11-02 10:00'), 'source' => AppointmentSource::Reception]);

        // Location B: 10:00–10:30 overlaps; 10:30 is inside the 20 min travel time; 10:45 is fine.
        foreach (['10:00', '10:30', '09:30'] as $time) {
            try {
                $this->booking()->book($offeringB, ['starts_at' => $this->at('2026-11-02 '.$time), 'source' => AppointmentSource::Reception]);
                $this->fail("Booking at {$time} in another location should be rejected");
            } catch (BookingException $e) {
                $this->assertSame(BookingException::SPECIALIST_OTHER_LOCATION, $e->reason);
            }
        }
        $ok = $this->booking()->book($offeringB, ['starts_at' => $this->at('2026-11-02 10:50'), 'source' => AppointmentSource::Reception]);
        $this->assertSame($locationB->id, $ok->location_id);

        $slotsB = $this->onlineSlots($offeringB);
        $this->assertNotContains('10:00', $slotsB);
        $this->assertNotContains('10:30', $slotsB);
        $this->assertContains('09:00', $slotsB);
    }

    /** Capacity > 1 with different durations never exceeds capacity at any instant (PRG-03). */
    public function test_capacity_with_different_durations(): void
    {
        $location = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $service = $this->makeService(['default_duration' => 30]);
        $offering = $this->makeOffering($location, $specialist, $service, ['capacity' => 2]);
        $this->makeSchedule($location, $specialist, [1 => [['09:00', '12:00']]]);

        $this->booking()->book($offering, ['starts_at' => $this->at('2026-11-02 09:00'), 'source' => AppointmentSource::Reception]);
        $offering->update(['duration_minutes' => 60]);
        $this->booking()->book($offering->fresh(), ['starts_at' => $this->at('2026-11-02 09:00'), 'source' => AppointmentSource::Reception]);
        $offering->update(['duration_minutes' => 30]);

        // 09:00–09:30 has two appointments → full; 09:30–10:00 has one (the 60 min) → one place left.
        try {
            $this->booking()->book($offering->fresh(), ['starts_at' => $this->at('2026-11-02 09:15'), 'source' => AppointmentSource::Reception]);
            $this->fail('Capacity exceeded');
        } catch (BookingException $e) {
            $this->assertSame(BookingException::CAPACITY_REACHED, $e->reason);
        }
        $this->booking()->book($offering->fresh(), ['starts_at' => $this->at('2026-11-02 09:30'), 'source' => AppointmentSource::Reception]);
        $this->expectExceptionObject(new BookingException(BookingException::CAPACITY_REACHED));
        $this->booking()->book($offering->fresh(), ['starts_at' => $this->at('2026-11-02 09:30'), 'source' => AppointmentSource::Reception]);
    }

    public function test_different_service_cannot_share_capacity(): void
    {
        $location = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $a = $this->makeOffering($location, $specialist, $this->makeService(), ['capacity' => 3]);
        $b = $this->makeOffering($location, $specialist, $this->makeService(), ['capacity' => 3]);
        $this->makeSchedule($location, $specialist, [1 => [['09:00', '12:00']]]);

        $this->booking()->book($a, ['starts_at' => $this->at('2026-11-02 09:00'), 'source' => AppointmentSource::Reception]);
        $this->expectExceptionObject(new BookingException(BookingException::SPECIALIST_BUSY));
        $this->booking()->book($b, ['starts_at' => $this->at('2026-11-02 09:00'), 'source' => AppointmentSource::Reception]);
    }

    public function test_buffers_and_resources_are_enforced(): void
    {
        $location = $this->makeLocation();
        $doctorA = $this->makeSpecialist();
        $doctorB = $this->makeSpecialist();
        $service = $this->makeService(['default_duration' => 30, 'buffer_after' => 15]);
        Resource::create(['location_id' => $location->id, 'type' => 'oct', 'name' => ['ro' => 'OCT', 'hu' => 'OCT']]);
        $offA = $this->makeOffering($location, $doctorA, $service, ['resource_type' => 'oct']);
        $offB = $this->makeOffering($location, $doctorB, $service, ['resource_type' => 'oct']);
        $this->makeSchedule($location, $doctorA, [1 => [['09:00', '12:00']]]);
        $this->makeSchedule($location, $doctorB, [1 => [['09:00', '12:00']]]);

        $first = $this->booking()->book($offA, ['starts_at' => $this->at('2026-11-02 09:00'), 'source' => AppointmentSource::Reception]);
        $this->assertNotNull($first->resource_id);

        // Buffer: doctor A is blocked until 09:45.
        $this->assertNotContains('09:30', $this->onlineSlots($offA));
        $this->assertContains('09:45', $this->onlineSlots($offA));

        // The single OCT device is busy for doctor B as well.
        try {
            $this->booking()->book($offB, ['starts_at' => $this->at('2026-11-02 09:15'), 'source' => AppointmentSource::Reception]);
            $this->fail('Resource should be busy');
        } catch (BookingException $e) {
            $this->assertSame(BookingException::RESOURCE_BUSY, $e->reason);
        }
        $this->assertSame($first->resource_id, $this->booking()->book($offB, ['starts_at' => $this->at('2026-11-02 09:45'), 'source' => AppointmentSource::Reception])->resource_id);
    }

    public function test_idempotency_key_prevents_duplicates(): void
    {
        [, , , $offering] = $this->basicSetup();
        $data = ['starts_at' => $this->at('2026-11-02 09:00'), 'source' => AppointmentSource::Online, 'idempotency_key' => 'form-token-123', 'contact_name' => 'Pacient Demo'];

        $first = $this->booking()->book($offering, $data);
        $second = $this->booking()->book($offering, $data);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Appointment::count());
    }

    /** PRG-04 / T03: reschedule reserves the new slot and frees the old one atomically. */
    public function test_reschedule_is_atomic(): void
    {
        [, , , $offering] = $this->basicSetup();
        $a = $this->booking()->book($offering, ['starts_at' => $this->at('2026-11-02 09:00'), 'source' => AppointmentSource::Reception]);
        $this->booking()->book($offering, ['starts_at' => $this->at('2026-11-02 10:00'), 'source' => AppointmentSource::Reception]);

        // Moving onto an occupied slot fails and leaves the original untouched.
        try {
            $this->booking()->reschedule($a, $this->at('2026-11-02 10:15'));
            $this->fail('Reschedule onto a busy slot must fail');
        } catch (BookingException $e) {
            $this->assertSame(BookingException::SPECIALIST_BUSY, $e->reason);
        }
        $this->assertSame('09:00', $a->fresh()->starts_at->setTimezone(self::TZ)->format('H:i'));

        // A 15 min shift overlapping its own old interval is allowed (self is excluded).
        $moved = $this->booking()->reschedule($a, $this->at('2026-11-02 09:15'));
        $this->assertSame($a->id, $moved->id);
        $this->assertSame(1, $moved->reschedule_count);

        $slots = $this->onlineSlots($offering);
        $this->assertNotContains('09:15', $slots);
        $this->assertNotContains('09:30', $slots); // overlaps 09:15–09:45

        // Moving away frees the old interval in the same operation.
        $this->booking()->reschedule($moved, $this->at('2026-11-02 10:30'));
        $slots = $this->onlineSlots($offering);
        $this->assertContains('09:00', $slots);
        $this->assertContains('09:15', $slots);
        $this->assertNotContains('10:30', $slots);
        $this->assertDatabaseCount('appointment_events', 4); // 2 created + 2 rescheduled; the failed move left no trace
    }

    public function test_reschedule_rejects_stale_version(): void
    {
        [, , , $offering] = $this->basicSetup();
        $a = $this->booking()->book($offering, ['starts_at' => $this->at('2026-11-02 09:00'), 'source' => AppointmentSource::Reception]);
        $staleVersion = $a->fresh()->lock_version;
        $this->booking()->changeStatus($a, AppointmentStatus::Arrived);

        $this->expectExceptionObject(new BookingException(BookingException::CONCURRENT_MODIFICATION));
        $this->booking()->reschedule($a, $this->at('2026-11-02 10:00'), expectedVersion: $staleVersion);
    }

    public function test_status_transitions_are_controlled(): void
    {
        [, , , $offering] = $this->basicSetup();
        $a = $this->booking()->book($offering, ['starts_at' => $this->at('2026-11-02 09:00'), 'source' => AppointmentSource::Reception]);

        $this->booking()->changeStatus($a, AppointmentStatus::Arrived);
        $this->booking()->changeStatus($a, AppointmentStatus::InConsultation);
        $this->booking()->changeStatus($a, AppointmentStatus::Completed);
        $this->assertSame(AppointmentStatus::Completed, $a->fresh()->status);

        $this->expectExceptionObject(new BookingException(BookingException::INVALID_TRANSITION));
        $this->booking()->changeStatus($a, AppointmentStatus::Confirmed);
    }

    public function test_expired_hold_no_longer_blocks_and_is_marked_expired(): void
    {
        [, , , $offering] = $this->basicSetup();
        $hold = $this->booking()->book($offering, ['starts_at' => $this->at('2026-11-02 09:00'), 'source' => AppointmentSource::Online, 'status' => AppointmentStatus::Held]);
        $this->assertNotContains('09:00', $this->onlineSlots($offering));

        $this->travel(11)->minutes();
        $this->assertContains('09:00', $this->onlineSlots($offering));

        $this->assertSame(1, $this->booking()->expireStale());
        $this->assertSame(AppointmentStatus::Expired, $hold->fresh()->status);
    }

    public function test_confirming_a_hold_rechecks_availability(): void
    {
        [, , , $offering] = $this->basicSetup();
        $hold = $this->booking()->book($offering, ['starts_at' => $this->at('2026-11-02 09:00'), 'source' => AppointmentSource::Online, 'status' => AppointmentStatus::Held]);

        // Hold expires and reception takes the slot in the meantime.
        $this->travel(11)->minutes();
        $this->booking()->book($offering, ['starts_at' => $this->at('2026-11-02 09:00'), 'source' => AppointmentSource::Reception]);

        $this->expectExceptionObject(new BookingException(BookingException::SPECIALIST_BUSY));
        $this->booking()->confirmHold($hold, ['contact_name' => 'Pacient Demo']);
    }

    public function test_outside_schedule_requires_explicit_override(): void
    {
        [, , , $offering] = $this->basicSetup();

        try {
            $this->booking()->book($offering, ['starts_at' => $this->at('2026-11-02 13:00'), 'source' => AppointmentSource::Reception]);
            $this->fail('Outside schedule must be rejected');
        } catch (BookingException $e) {
            $this->assertSame(BookingException::OUTSIDE_SCHEDULE, $e->reason);
        }

        $extra = $this->booking()->book($offering, ['starts_at' => $this->at('2026-11-02 13:00'), 'source' => AppointmentSource::Reception, 'allow_outside_schedule' => true]);
        $this->assertNotNull($extra->id);
    }

    public function test_online_booking_rejected_during_leave_even_if_requested_directly(): void
    {
        [, $specialist, , $offering] = $this->basicSetup();
        ScheduleException::create(['specialist_id' => $specialist->id, 'kind' => 'unavailable', 'reason' => 'leave', 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-02']);

        $this->expectExceptionObject(new BookingException(BookingException::OUTSIDE_SCHEDULE));
        $this->booking()->book($offering, ['starts_at' => $this->at('2026-11-02 09:00'), 'source' => AppointmentSource::Online]);
    }
}
