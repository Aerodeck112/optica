<?php

namespace Tests\Feature\Loyalty;

use App\Modules\Catalog\Models\Package;
use App\Modules\Core\Models\Integration;
use App\Modules\Finance\Models\Payment;
use App\Modules\Loyalty\Models\Voucher;
use App\Modules\Loyalty\Models\VoucherOrder;
use App\Modules\Notifications\Models\MessageLog;
use Database\Seeders\BaseDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class VoucherOrderTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_fictiv';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BaseDataSeeder::class);
        foreach (['sesiune-vr' => ['per_person', 11000], 'arena-privata' => ['flat', 88000]] as $slug => [$model, $price]) {
            Package::query()->create(['slug' => ['ro' => $slug], 'name' => ['ro' => $slug], 'type' => 'session', 'pricing_model' => $model,
                'price_minor' => $price, 'max_participants' => 8, 'slot_minutes' => 60, 'is_active' => true, 'published_locales' => ['ro']]);
        }
    }

    private function enableStripe(): void
    {
        $integration = Integration::query()->firstOrNew(['key' => 'payments']);
        $integration->forceFill(['driver' => 'stripe', 'mode' => 'sandbox', 'status' => 'sandbox_tested']);
        $integration->storeCredentials(['secret_key' => 'sk_test_fictiv', 'webhook_secret' => self::WEBHOOK_SECRET]);
        $integration->save();
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_v1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_v1'])]);
    }

    private function order(string $product = 'doi')
    {
        return $this->post(route('forms.voucher'), [
            'product' => $product, 'buyer_name' => 'Ana Exemplu', 'buyer_email' => 'ana@example.test',
            'recipient_name' => 'Andrei', 'message' => 'La mulți ani!', 'accept_terms' => '1', '_started' => time() - 30,
        ]);
    }

    private function paidWebhook(Payment $payment, string $eventId = 'evt_v1')
    {
        $payload = json_encode(['id' => $eventId, 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => $payment->provider_reference, 'client_reference_id' => $payment->uuid, 'payment_status' => 'paid',
            'amount_total' => $payment->amount_minor, 'currency' => 'ron', 'payment_intent' => 'pi_test_v1',
        ]]]);
        $timestamp = time();

        return $this->call('POST', route('webhooks.handle', ['provider' => 'stripe']), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1=".hash_hmac('sha256', $timestamp.'.'.$payload, self::WEBHOOK_SECRET),
        ], $payload);
    }

    public function test_paid_order_issues_one_voucher_and_emails_the_buyer(): void
    {
        $this->enableStripe();
        $this->get(route('voucher.order.create', ['tip' => 'doi']))->assertOk()->assertSee('Voucher pentru doi — 220 lei', false);

        $this->order()->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_v1');
        $order = VoucherOrder::query()->sole();
        $this->assertSame(22000, (int) $order->amount_minor);
        $this->assertSame('voucher_sale', $order->payment->purpose);
        $this->assertNull($order->payment->booking_id);

        $this->paidWebhook($order->payment)->assertOk();
        $this->paidWebhook($order->payment, 'evt_v1_retrimis')->assertOk();

        $voucher = Voucher::query()->sole();
        $this->assertSame('package', $voucher->type);
        $this->assertSame(2, (int) $voucher->package_participants);
        $this->assertSame('Andrei', $voucher->recipient_name);
        $this->assertSame(now()->addMonthsNoOverflow(6)->toDateString(), $voucher->valid_until->toDateString());
        $this->assertSame('issued', $order->fresh()->status);
        $this->assertSame(1, MessageLog::query()->where('template_key', 'voucher.purchased')->where('recipient_hash', hash('sha256', 'email|ana@example.test'))->count());

        $this->get(route('voucher.order.show', ['order' => $order->uuid]))->assertForbidden();
        $this->get(URL::signedRoute('voucher.order.show', ['order' => $order->uuid]))->assertOk()->assertSee($voucher->code);
        $this->get(URL::signedRoute('voucher.order.download', ['order' => $order->uuid]))->assertOk()->assertSee($voucher->code);
    }

    public function test_without_online_payments_the_order_page_points_to_contact(): void
    {
        $this->get(route('voucher.order.create'))->assertOk()->assertSee('Plata online pentru vouchere va fi disponibilă în curând');
        $this->order()->assertSessionHasErrors('product');
        $this->assertSame(0, VoucherOrder::query()->count());
    }
}
