<?php

namespace Tests\Feature\Crm;

use App\Modules\Booking\Models\Booking;
use App\Modules\Crm\Models\Consent;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\ConsentService;
use App\Modules\Crm\Services\CustomerDataService;
use App\Modules\Crm\Services\CustomerMergeService;
use App\Modules\Loyalty\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\BookingFixtures;
use Tests\TestCase;

class CustomerMergeAndErasureTest extends TestCase
{
    use BookingFixtures, RefreshDatabase;

    public function test_merge_moves_history_transfers_points_and_retires_duplicate(): void
    {
        $keep = Customer::factory()->create(['phone' => null, 'phone_normalized' => null]);
        $dup = Customer::factory()->create(['phone' => '0740 111 222', 'phone_normalized' => '+40740111222']);
        $booking = $this->bookingFor($dup, 'confirmed');
        app(LoyaltyService::class)->post($dup, 40, 'adjust', 'seed-dup');

        app(CustomerMergeService::class)->merge($keep, $dup, null);

        $this->assertSame($keep->id, $booking->refresh()->customer_id);
        $this->assertSame(40, app(LoyaltyService::class)->balance($keep));
        $this->assertSame(0, app(LoyaltyService::class)->balance($dup->refresh()));
        $this->assertSame($keep->id, $dup->merged_into_id);
        $this->assertNull($dup->email_normalized);
        $this->assertSame('+40740111222', $keep->refresh()->phone_normalized, 'Telefonul lipsă se completează din duplicat.');
    }

    public function test_merge_keeps_the_most_recent_consent_decision(): void
    {
        $keep = Customer::factory()->create();
        $dup = Customer::factory()->create();
        $consents = app(ConsentService::class);
        $consents->grant($keep, 'email', 'booking_form');
        $this->travel(1)->days();
        $consents->withdraw($dup, 'email', 'unsubscribe_link');

        app(CustomerMergeService::class)->merge($keep, $dup, null);

        $this->assertSame('withdrawn', Consent::query()->where('customer_id', $keep->id)->where('channel', 'email')->value('status'));
        $this->assertSame(1, Consent::query()->where('customer_id', $keep->id)->count());
    }

    public function test_export_contains_bookings_and_consents(): void
    {
        Storage::fake('local');
        $customer = Customer::factory()->create();
        $this->bookingFor($customer, 'completed');
        app(ConsentService::class)->grant($customer, 'sms', 'booking_form');

        $request = app(CustomerDataService::class)->export($customer, null);

        $data = json_decode(Storage::disk('local')->get($request->export_path), true);
        $this->assertSame($customer->email, $data['customer']['email']);
        $this->assertCount(1, $data['bookings']);
        $this->assertSame('sms', $data['marketing_consents'][0]['channel']);
    }

    public function test_anonymization_is_refused_while_bookings_are_active(): void
    {
        $customer = Customer::factory()->create();
        $this->bookingFor($customer, 'confirmed');

        $this->expectException(RuntimeException::class);
        app(CustomerDataService::class)->anonymize($customer, null, 'Cerere client');
    }

    public function test_anonymization_removes_contact_data_and_blocks_future_marketing(): void
    {
        $customer = Customer::factory()->create();
        $email = $customer->email;
        $booking = $this->bookingFor($customer, 'completed', ['payment_status' => 'paid', 'contact_email' => $email, 'customer_notes' => 'Aniversarea lui Ana']);
        app(ConsentService::class)->grant($customer, 'email', 'booking_form');

        app(CustomerDataService::class)->anonymize($customer, null, 'Cerere client');

        $customer->refresh();
        $this->assertNull($customer->email);
        $this->assertNotNull($customer->anonymized_at);
        $this->assertNull($booking->refresh()->contact_email);
        $this->assertNull($booking->customer_notes);
        $this->assertTrue(app(ConsentService::class)->isSuppressed('email', $email, 'marketing'));
        $this->assertFalse(app(ConsentService::class)->canReceiveMarketing($customer, 'email'));
    }

    private function bookingFor(Customer $customer, string $status, array $extra = []): Booking
    {
        return Booking::query()->create($extra + [
            'code' => 'AW'.strtoupper(substr(md5(uniqid('', true)), 0, 7)),
            'package_id' => $this->package($this->arena())->id,
            'customer_id' => $customer->id,
            'participants' => 2,
            'status' => $status,
            'contact_name' => 'Persoană Test',
        ]);
    }
}
