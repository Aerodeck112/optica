<?php

namespace Tests\Feature\Crm;

use App\Modules\Booking\Services\BookingException;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\LeadService;
use App\Support\LocalTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BookingFixtures;
use Tests\TestCase;

class LeadConversionTest extends TestCase
{
    use BookingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSchedule('10:00', '22:00');
        $this->setting('booking.slot_interval_minutes', 60);
    }

    public function test_confirmed_lead_becomes_a_booking_linked_to_the_same_customer(): void
    {
        $lead = app(LeadService::class)->create(['type' => 'birthday', 'contact_name' => 'Ioana Test', 'contact_email' => 'ioana@example.test', 'participants' => 8]);
        $start = LocalTime::fromLocal(LocalTime::now()->addDays(5)->toDateString(), '12:00');

        $booking = app(LeadService::class)->convertToBooking($lead, $this->package($this->arena()), $start, 8, null);

        $lead->refresh();
        $this->assertSame($booking->id, $lead->booking_id);
        $this->assertSame('confirmed', $lead->stage);
        $this->assertSame($lead->customer_id, $booking->customer_id);
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame($lead->id, $booking->lead_id);
    }

    public function test_conversion_respects_availability(): void
    {
        $package = $this->package($this->arena());
        $start = LocalTime::fromLocal(LocalTime::now()->addDays(5)->toDateString(), '12:00');
        $service = app(LeadService::class);
        $first = $service->create(['type' => 'corporate', 'contact_name' => 'Firma A', 'contact_email' => 'a@example.test']);
        $second = $service->create(['type' => 'corporate', 'contact_name' => 'Firma B', 'contact_email' => 'b@example.test']);
        $service->convertToBooking($first, $package, $start, 4, null);

        $this->expectException(BookingException::class);
        $service->convertToBooking($second, $package, $start, 4, null);
    }

    public function test_lead_stage_changes_are_recorded(): void
    {
        $lead = app(LeadService::class)->create(['type' => 'group', 'contact_name' => 'Grup', 'contact_phone' => '0740000001']);

        app(LeadService::class)->moveTo($lead, 'lost', null, null, 'price');

        $this->assertSame('lost', $lead->refresh()->stage);
        $this->assertSame('price', $lead->lost_reason);
        $this->assertSame(['from' => 'new', 'to' => 'lost'], Lead::query()->find($lead->id)->activities()->first()->data);
    }
}
