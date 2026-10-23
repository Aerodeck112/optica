<?php

namespace Tests\Feature\Booking;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\SlotHold;
use App\Modules\Booking\Services\BookingData;
use App\Modules\Booking\Services\BookingException;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Catalog\Models\Package;
use App\Modules\Crm\Models\Customer;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BookingFixtures;
use Tests\TestCase;

class BookingServiceTest extends TestCase
{
    use BookingFixtures, RefreshDatabase;

    private Package $pkg;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));
        $this->seedSchedule();
        $this->setting('booking.slot_interval_minutes', 60);
        $this->setting('booking.min_lead_minutes', 60);
        $this->setting('booking.auto_confirm', true);
        $this->publishPolicies();
        $this->pkg = $this->package($this->arena());
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function data(array $overrides = []): BookingData
    {
        $args = $overrides + [
            'package' => $this->pkg,
            'startsAt' => LocalTime::fromLocal('2026-10-22', '15:00'),
            'participants' => 4,
            'contactName' => 'Maria Pop',
            'contactEmail' => 'maria@example.test',
            'contactPhone' => '0722 000 111',
            'source' => 'web',
            'acceptedPolicyTypes' => ['terms', 'booking_policy'],
            'ageGroups' => ['adults' => 4],
        ];

        return new BookingData(...$args);
    }

    public function test_web_booking_with_configured_price_is_created_with_price_and_occupancy(): void
    {
        $result = app(BookingService::class)->create($this->data());
        $booking = $result['booking'];

        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('unpaid', $booking->payment_status);
        $this->assertSame(20000, $booking->total_minor); // 4 × 50,00 lei
        $this->assertCount(1, $booking->occupancies);
        $this->assertTrue($booking->occupancies->first()->is_active);
        $this->assertSame(hash('sha256', $result['manage_token']), $booking->manage_token_hash);
        $this->assertCount(2, $booking->accepted_policies);
        $this->assertMatchesRegularExpression('/^AW[A-Z0-9]{7}$/', $booking->code);
    }

    public function test_without_auto_confirm_booking_waits_for_reception_but_still_holds_the_slot(): void
    {
        $this->setting('booking.auto_confirm', false);
        $booking = app(BookingService::class)->create($this->data())['booking'];

        $this->assertSame('pending', $booking->status);
        $this->assertTrue($booking->occupancies()->where('is_active', true)->exists());

        $this->expectException(BookingException::class);
        app(BookingService::class)->create($this->data(['contactEmail' => 'altcineva@example.test']));
    }

    public function test_package_without_price_creates_availability_request_that_does_not_block_the_arena(): void
    {
        $this->pkg->update(['price_minor' => null]);

        $request = app(BookingService::class)->create($this->data())['booking'];
        $this->assertSame('request', $request->status);
        $this->assertNull($request->total_minor);
        $this->assertCount(0, $request->occupancies);

        // același interval poate fi rezervat de recepție
        $staff = app(BookingService::class)->create($this->data(['source' => 'reception', 'acceptedPolicyTypes' => []]))['booking'];
        $this->assertSame('confirmed', $staff->status);

        // confirmarea cererii verifică disponibilitatea și refuză suprapunerea
        $this->expectException(BookingException::class);
        app(BookingService::class)->confirm($request);
    }

    public function test_policies_must_be_accepted_for_public_bookings(): void
    {
        $this->expectExceptionMessage('condițiile');
        app(BookingService::class)->create($this->data(['acceptedPolicyTypes' => ['terms']]));
    }

    public function test_minors_require_the_minors_policy(): void
    {
        try {
            app(BookingService::class)->create($this->data(['ageGroups' => ['under_12' => 3, 'adults' => 1]]));
            $this->fail('Trebuia cerută acceptarea regulilor pentru minori.');
        } catch (BookingException $e) {
            $this->assertSame('policies', $e->reason);
        }

        $booking = app(BookingService::class)->create($this->data([
            'ageGroups' => ['under_12' => 3, 'adults' => 1],
            'acceptedPolicyTypes' => ['terms', 'booking_policy', 'minors'],
        ]))['booking'];
        $this->assertSame(['under_12' => 3, 'adults' => 1], $booking->age_groups);
    }

    public function test_hold_blocks_other_visitors_and_only_the_holder_can_use_it(): void
    {
        $start = LocalTime::fromLocal('2026-10-22', '15:00');
        ['hold' => $hold, 'token' => $token] = app(BookingService::class)->hold($this->pkg, $start, 4);

        try {
            app(BookingService::class)->create($this->data(['contactEmail' => 'intrus@example.test']));
            $this->fail('Slotul ținut temporar nu trebuie să poată fi rezervat de altcineva.');
        } catch (BookingException $e) {
            $this->assertSame('slot_unavailable', $e->reason);
        }

        // token greșit = fără drept asupra hold-ului
        try {
            app(BookingService::class)->create($this->data(['holdPublicId' => $hold->public_id, 'holdToken' => 'gresit']));
            $this->fail('Un token greșit nu trebuie să folosească hold-ul.');
        } catch (BookingException) {
        }

        $booking = app(BookingService::class)->create($this->data(['holdPublicId' => $hold->public_id, 'holdToken' => $token]))['booking'];
        $this->assertSame('confirmed', $booking->status);
        $this->assertNotNull($hold->fresh()->released_at);
        $this->assertSame($booking->id, $hold->fresh()->booking_id);
    }

    public function test_expired_hold_frees_the_slot(): void
    {
        $this->setting('booking.hold_minutes', 10);
        $start = LocalTime::fromLocal('2026-10-22', '15:00');
        app(BookingService::class)->hold($this->pkg, $start, 4);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(11));

        $booking = app(BookingService::class)->create($this->data(['contactEmail' => 'urmatorul@example.test']))['booking'];
        $this->assertSame('confirmed', $booking->status);
    }

    public function test_expired_hold_owner_can_still_book_if_slot_is_free_but_not_if_taken(): void
    {
        $this->setting('booking.hold_minutes', 10);
        $start = LocalTime::fromLocal('2026-10-22', '15:00');
        ['hold' => $hold, 'token' => $token] = app(BookingService::class)->hold($this->pkg, $start, 4);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(11));

        app(BookingService::class)->create($this->data(['contactEmail' => 'rapid@example.test']));

        try {
            app(BookingService::class)->create($this->data(['holdPublicId' => $hold->public_id, 'holdToken' => $token]));
            $this->fail('Hold-ul expirat nu mai garantează slotul.');
        } catch (BookingException $e) {
            $this->assertSame('hold_expired', $e->reason);
        }
    }

    public function test_reschedule_checks_availability_and_moves_occupancy(): void
    {
        $service = app(BookingService::class);
        $first = $service->create($this->data())['booking'];
        $service->create($this->data(['startsAt' => LocalTime::fromLocal('2026-10-22', '17:00'), 'contactEmail' => 'b@example.test']));

        try {
            $service->reschedule($first, LocalTime::fromLocal('2026-10-22', '17:00'), 'user', null);
            $this->fail('Reprogramarea peste altă rezervare trebuie refuzată.');
        } catch (BookingException $e) {
            $this->assertSame('slot_unavailable', $e->reason);
        }

        $moved = $service->reschedule($first, LocalTime::fromLocal('2026-10-22', '19:00'), 'user', null);
        $this->assertSame('2026-10-22 16:00:00', $moved->starts_at->format('Y-m-d H:i:s')); // 19:00 EEST = 16:00 UTC
        $this->assertSame(1, $moved->occupancies()->where('is_active', true)->count());
        $this->assertSame(1, $moved->reschedule_count);

        // vechiul interval s-a eliberat
        $service->create($this->data(['contactEmail' => 'c@example.test']));
    }

    public function test_reschedule_across_dst_change(): void
    {
        $service = app(BookingService::class);
        // sâmbătă 24 oct, 18:00 EEST (UTC+3) → luni 26 oct, 18:00 EET (UTC+2)
        $booking = $service->create($this->data(['startsAt' => LocalTime::fromLocal('2026-10-24', '18:00')]))['booking'];
        $this->assertSame('15:00', $booking->starts_at->format('H:i'));

        $moved = $service->reschedule($booking, LocalTime::fromLocal('2026-10-26', '18:00'), 'customer');
        $this->assertSame('16:00', $moved->starts_at->format('H:i'));
        $this->assertSame('18:00', LocalTime::toLocal($moved->starts_at)->format('H:i'));
    }

    public function test_cancel_frees_slot_and_closed_bookings_cannot_change(): void
    {
        $service = app(BookingService::class);
        $booking = $service->create($this->data())['booking'];

        $service->cancel($booking, 'Clientul a anulat telefonic', 'user', null);
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertFalse($booking->occupancies()->where('is_active', true)->exists());

        $service->create($this->data(['contactEmail' => 'nou@example.test']));

        $this->expectException(BookingException::class);
        $service->transition($booking->fresh(), 'checked_in');
    }

    public function test_pending_bookings_expire_and_release_the_arena(): void
    {
        $this->pkg->update(['deposit_type' => 'percent', 'deposit_value' => 3000]);
        $service = app(BookingService::class);
        $booking = $service->create($this->data(['paymentMethod' => 'online']))['booking'];

        $this->assertSame('pending', $booking->status);
        $this->assertSame(6000, $booking->deposit_minor);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(31));
        $this->assertSame(1, $service->expireDue());
        $this->assertSame('expired', $booking->fresh()->status);
        $this->assertFalse($booking->occupancies()->where('is_active', true)->exists());
    }

    public function test_check_in_and_completion_update_customer_visits(): void
    {
        $service = app(BookingService::class);
        $result = $service->create($this->data());
        $service->checkInByToken($result['checkin_token'], 1);
        $service->transition($result['booking']->fresh(), 'completed');

        $this->assertSame('completed', $result['booking']->fresh()->status);
        $this->assertSame(1, $result['booking']->customer->fresh()->visits_count);
    }

    public function test_customer_is_deduplicated_by_normalized_email_and_phone(): void
    {
        $service = app(BookingService::class);
        $service->create($this->data(['contactEmail' => 'Maria@Example.test']));
        $service->create($this->data([
            'startsAt' => LocalTime::fromLocal('2026-10-22', '18:00'),
            'contactEmail' => null,
            'contactPhone' => '+40722000111',
        ]));

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(2, Customer::query()->first()->bookings_count);
    }

    public function test_marketing_consent_is_separate_from_booking_terms(): void
    {
        $service = app(BookingService::class);
        $booking = $service->create($this->data())['booking'];
        $this->assertFalse($booking->customer->hasMarketingConsent('email'));

        $other = $service->create($this->data([
            'startsAt' => LocalTime::fromLocal('2026-10-22', '18:00'),
            'contactEmail' => 'abonat@example.test',
            'contactPhone' => null,
            'marketingConsent' => ['email' => true],
            'consentContext' => ['text' => 'Vreau oferte pe e-mail'],
        ]))['booking'];
        $this->assertTrue($other->customer->hasMarketingConsent('email'));
        $this->assertFalse($other->customer->hasMarketingConsent('sms'));
    }

    public function test_participants_are_validated_against_package_limits(): void
    {
        $this->expectExceptionMessage('maximum 10');
        app(BookingService::class)->create($this->data(['participants' => 12]));
    }

    public function test_slot_outside_opening_hours_is_rejected_for_visitors(): void
    {
        $this->expectException(BookingException::class);
        app(BookingService::class)->create($this->data(['startsAt' => LocalTime::fromLocal('2026-10-22', '23:00')]));
    }

    public function test_internal_recurring_block_rejected_when_overlapping_bookings(): void
    {
        $service = app(BookingService::class);
        $booking = $service->create($this->data())['booking'];
        $resource = $booking->occupancies->first()->resource;

        $intervals = [];
        foreach (['2026-10-21', '2026-10-22'] as $date) {
            $intervals[] = [LocalTime::fromLocal($date, '15:00'), LocalTime::fromLocal($date, '16:00')];
        }

        try {
            $service->blockResource($resource, $intervals, 'maintenance', 'Calibrare', null);
            $this->fail('Seria se suprapune cu o rezervare și trebuie refuzată integral.');
        } catch (BookingException $e) {
            $this->assertSame('block_conflict', $e->reason);
        }

        $this->assertCount(1, $service->blockResource($resource, [$intervals[0]], 'maintenance', 'Calibrare', null));
        $this->assertSame(0, SlotHold::query()->count());
        $this->assertSame(1, Booking::query()->count());
    }
}
