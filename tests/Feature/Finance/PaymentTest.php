<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingData;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Core\Models\Integration;
use App\Modules\Core\Models\WebhookEvent;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\Payments\PaymentException;
use App\Modules\Finance\Services\Payments\PaymentService;
use App\Modules\Finance\Services\Payments\RefundService;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Database\Seeders\BaseDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Support\BookingFixtures;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use BookingFixtures, RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_fictiv';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));
        $this->seed(BaseDataSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seedSchedule();
        $this->setting('booking.slot_interval_minutes', 60);
        $this->setting('booking.min_lead_minutes', 60);
        $this->setting('booking.auto_confirm', true);
        $this->setting('booking.online_enabled', true);
        $this->publishPolicies();

        $integration = Integration::query()->firstOrNew(['key' => 'payments']);
        $integration->forceFill(['driver' => 'stripe', 'mode' => 'sandbox', 'status' => 'sandbox_tested']);
        $integration->storeCredentials(['secret_key' => 'sk_test_fictiv', 'webhook_secret' => self::WEBHOOK_SECRET]);
        $integration->save();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Rezervare online cu avans 30% (4 × 50 lei = 200 lei, avans 60 lei). */
    private function onlineBooking(): Booking
    {
        $package = $this->package($this->arena(), ['deposit_type' => 'percent', 'deposit_value' => 3000]);

        return app(BookingService::class)->create(new BookingData(
            package: $package,
            startsAt: LocalTime::fromLocal('2026-10-22', '15:00'),
            participants: 4,
            contactName: 'Client Fictiv',
            contactEmail: 'client@example.test',
            contactPhone: '0700 000 000',
            source: 'web',
            acceptedPolicyTypes: ['terms', 'booking_policy'],
            ageGroups: ['adults' => 4],
            paymentMethod: 'online',
        ))['booking'];
    }

    private function fakeCheckout(): void
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])]);
    }

    private function postWebhook(array $event, ?string $secret = self::WEBHOOK_SECRET, ?int $timestamp = null)
    {
        $payload = json_encode($event);
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, (string) $secret);

        return $this->call('POST', route('webhooks.handle', ['provider' => 'stripe']), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload);
    }

    private function completedEvent(Payment $payment, string $eventId = 'evt_1', ?int $amount = null): array
    {
        return ['id' => $eventId, 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => $payment->provider_reference, 'client_reference_id' => $payment->uuid, 'payment_status' => 'paid',
            'amount_total' => $amount ?? $payment->amount_minor, 'currency' => 'ron', 'payment_intent' => 'pi_test_1',
        ]]];
    }

    public function test_online_checkout_requests_only_the_deposit_and_stores_no_card_data(): void
    {
        $this->fakeCheckout();
        $booking = $this->onlineBooking();
        $this->assertSame('pending', $booking->status);
        $this->assertSame(6000, $booking->deposit_minor);

        $payment = app(PaymentService::class)->startOnline($booking, 'https://example.test/ok', 'https://example.test/back');

        $this->assertSame(6000, $payment->amount_minor);
        $this->assertSame('cs_test_1', $payment->provider_reference);
        Http::assertSent(fn (HttpRequest $request) => $request['line_items'][0]['price_data']['unit_amount'] === 6000
            && $request->hasHeader('Idempotency-Key', 'checkout-'.$payment->uuid)
            && $request['client_reference_id'] === $payment->uuid);

        // o a doua cerere reia aceeași sesiune, nu creează alta
        $again = app(PaymentService::class)->startOnline($booking->fresh(), 'https://example.test/ok', 'https://example.test/back');
        $this->assertTrue($again->is($payment));
        Http::assertSentCount(1);

        $columns = array_keys($payment->fresh()->getAttributes());
        $this->assertEmpty(array_filter($columns, fn ($column) => preg_match('/card|pan|cvv|cvc|expiry/i', $column)));
    }

    public function test_signed_webhook_confirms_booking_once_even_when_delivered_twice(): void
    {
        $this->fakeCheckout();
        $booking = $this->onlineBooking();
        $payment = app(PaymentService::class)->startOnline($booking, 'https://example.test/ok', 'https://example.test/back');

        $this->postWebhook($this->completedEvent($payment))->assertOk();
        $this->postWebhook($this->completedEvent($payment))->assertOk();
        // alt eveniment pentru aceeași sesiune (ex. async_payment_succeeded) nu dublează încasarea
        $second = $this->completedEvent($payment, 'evt_2');
        $second['type'] = 'checkout.session.async_payment_succeeded';
        $this->postWebhook($second)->assertOk();

        $booking->refresh();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame(6000, $booking->paid_minor);
        $this->assertSame('deposit_paid', $booking->payment_status);
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame('pi_test_1', $payment->fresh()->provider_data['payment_intent']);
        $this->assertSame(2, WebhookEvent::query()->where('provider', 'stripe')->where('signature_valid', true)->count());
    }

    public function test_webhook_with_bad_signature_or_old_timestamp_changes_nothing(): void
    {
        $this->fakeCheckout();
        $booking = $this->onlineBooking();
        $payment = app(PaymentService::class)->startOnline($booking, 'https://example.test/ok', 'https://example.test/back');

        $this->postWebhook($this->completedEvent($payment), 'whsec_alt')->assertStatus(400);
        $this->postWebhook($this->completedEvent($payment), self::WEBHOOK_SECRET, time() - 600)->assertStatus(400);

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('pending', $booking->fresh()->status);
    }

    public function test_amount_mismatch_is_not_applied_and_is_flagged_for_review(): void
    {
        $this->fakeCheckout();
        $booking = $this->onlineBooking();
        $payment = app(PaymentService::class)->startOnline($booking, 'https://example.test/ok', 'https://example.test/back');

        $this->postWebhook($this->completedEvent($payment, 'evt_x', 100))->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, $booking->fresh()->paid_minor);
        $this->assertSame('failed', WebhookEvent::query()->where('event_id', 'evt_x')->value('status'));
    }

    public function test_reception_records_payments_and_booking_status_follows(): void
    {
        $this->setting('booking.auto_confirm', true);
        $booking = $this->onlineBooking();
        $reception = tap(User::factory()->create(), fn ($user) => $user->assignRole('reception'));
        $payments = app(PaymentService::class);

        $payments->recordManual($booking, 'cash', 6000, $reception, idempotencyKey: 'form-1');
        $payments->recordManual($booking, 'cash', 6000, $reception, idempotencyKey: 'form-1'); // dublu-click
        $booking->refresh();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('deposit_paid', $booking->payment_status);
        $this->assertSame(14000, $booking->balanceMinor());

        $payments->recordManual($booking, 'card_pos', 14000, $reception);
        $this->assertSame('paid', $booking->fresh()->payment_status);

        $this->expectException(PaymentException::class);
        $payments->recordManual($booking->fresh(), 'cash', 100, $reception);
    }

    public function test_refund_needs_approval_permission_and_updates_the_booking(): void
    {
        $booking = $this->onlineBooking();
        $reception = tap(User::factory()->create(), fn ($user) => $user->assignRole('reception'));
        $manager = tap(User::factory()->create(), fn ($user) => $user->assignRole('manager'));
        $payment = app(PaymentService::class)->recordManual($booking, 'cash', 20000, $reception);
        $refunds = app(RefundService::class);

        $refund = $refunds->request($payment, 5000, 'Un participant nu a mai venit', $reception);
        $this->assertSame(15000, $refunds->refundable($payment->fresh()));

        try {
            $refunds->approve($refund, $reception);
            $this->fail('Recepția nu poate aproba rambursări.');
        } catch (PaymentException $e) {
            $this->assertSame('forbidden', $e->reason);
        }

        $refunds->approve($refund, $manager);
        $this->assertSame('succeeded', $refund->fresh()->status);
        $this->assertSame('partially_refunded', $payment->fresh()->status);
        $booking->refresh();
        $this->assertSame(5000, $booking->refunded_minor);
        $this->assertSame('partially_refunded', $booking->payment_status);

        $this->expectException(PaymentException::class);
        $refunds->approve($refund, $manager); // a doua aprobare nu mai returnează bani
    }

    public function test_online_refund_goes_through_the_processor_with_an_idempotency_key(): void
    {
        $this->fakeCheckout();
        $booking = $this->onlineBooking();
        $payment = app(PaymentService::class)->startOnline($booking, 'https://example.test/ok', 'https://example.test/back');
        $this->postWebhook($this->completedEvent($payment))->assertOk();
        $manager = tap(User::factory()->create(), fn ($user) => $user->assignRole('manager'));

        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_test_1', 'status' => 'succeeded'])]);
        $refund = app(RefundService::class)->request($payment->fresh(), 6000, 'Anulare în termen', $manager);
        app(RefundService::class)->approve($refund, $manager);

        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/v1/refunds')
            && $request['payment_intent'] === 'pi_test_1' && (int) $request['amount'] === 6000
            && $request->hasHeader('Idempotency-Key', $refund->idempotency_key));
        $this->assertSame('re_test_1', $refund->fresh()->provider_reference);
        $this->assertSame('refunded', $booking->fresh()->payment_status);
    }

    public function test_public_booking_form_redirects_to_the_processor_for_online_deposit(): void
    {
        $this->fakeCheckout();
        $package = $this->package($this->arena(), ['deposit_type' => 'percent', 'deposit_value' => 3000]);
        $starts = LocalTime::fromLocal('2026-10-22', '15:00');

        $response = $this->post(route('forms.booking'), [
            'package_id' => $package->id,
            'starts_at' => $starts->toIso8601String(),
            'participants' => 4,
            'age_groups' => ['adults' => 4],
            'contact_name' => 'Client Fictiv',
            'contact_email' => 'client@example.test',
            'contact_phone' => '0700000000',
            'payment_method' => 'online',
            'accept_terms' => 1,
            'locale' => 'ro',
            'form_started_at' => now()->subMinute()->timestamp,
        ]);

        $response->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');
        $this->assertSame('pending', Booking::query()->latest('id')->first()->status);
    }
}
