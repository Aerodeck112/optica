<?php

namespace Tests\Feature\Finance;

use App\Modules\Core\Models\Integration;
use App\Modules\Core\Settings\SettingsRepository;
use App\Modules\Integrations\Services\IntegrationTester;
use Database\Seeders\BaseDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StripeSetupTest extends TestCase
{
    use RefreshDatabase;

    private function stripe(array $credentials, string $mode = 'sandbox'): Integration
    {
        $integration = Integration::query()->where('key', 'payments')->firstOrFail();
        $integration->forceFill(['driver' => 'stripe', 'mode' => $mode, 'status' => 'adapter_ready'])->storeCredentials($credentials);
        $integration->save();

        return $integration;
    }

    public function test_the_stripe_check_explains_what_is_missing_and_enables_online_payment_when_everything_is_set(): void
    {
        $this->seed(BaseDataSeeder::class);
        $url = rtrim(config('app.url'), '/').'/api/webhooks/stripe';
        $endpoints = [];
        Http::fake([
            'api.stripe.com/v1/balance' => Http::response(['object' => 'balance']),
            'api.stripe.com/v1/webhook_endpoints*' => function () use (&$endpoints) {
                return Http::response(['data' => $endpoints]);
            },
        ]);
        $tester = app(IntegrationTester::class);

        $this->assertStringContainsString('Publishable key', $tester->test($this->stripe(['secret_key' => 'pk_test_123']))['message']);
        $this->assertStringContainsString('cheie live', $tester->test($this->stripe(['secret_key' => 'sk_live_123']))['message']);

        $result = $tester->test($this->stripe(['secret_key' => 'sk_test_123']));
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('nu există webhook-ul', $result['message']);
        $this->assertFalse(Integration::query()->where('key', 'payments')->sole()->isUsable());

        $endpoints = [['url' => $url, 'status' => 'enabled', 'enabled_events' => ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired']]];
        $this->assertStringContainsString('Signing secret', $tester->test($this->stripe(['secret_key' => 'sk_test_123']))['message']);

        $result = $tester->test($this->stripe(['secret_key' => 'sk_test_123', 'webhook_secret' => 'whsec_abc']));
        $this->assertTrue($result['ok'], $result['message']);
        $this->assertTrue(Integration::query()->where('key', 'payments')->sole()->isUsable());
        $this->assertContains('online', (array) app(SettingsRepository::class)->get('booking.payment_methods'));
    }
}
