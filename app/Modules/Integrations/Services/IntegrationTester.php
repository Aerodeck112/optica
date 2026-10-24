<?php

namespace App\Modules\Integrations\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Core\Models\Integration;
use App\Modules\Finance\Efactura\AnafClient;
use App\Modules\Finance\Models\FiscalDevice;
use App\Modules\Notifications\Services\ProviderFactory;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Teste de conexiune fără efecte asupra clienților: nu se trimit mesaje, nu se creează plăți și nu se emit documente.
 * Un test reușit marchează integrarea „Testat în sandbox” sau „Configurat (live)”, niciodată „verificat în producție” —
 * acel status se setează manual, după o tranzacție reală reușită.
 */
class IntegrationTester
{
    public function __construct(private readonly ProviderFactory $providers, private readonly AuditLogger $audit) {}

    /** @return array{ok: bool, message: string} */
    public function test(Integration $integration): array
    {
        try {
            $result = $this->run($integration);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'message' => 'Eroare: '.mb_substr($e->getMessage(), 0, 300)];
        }

        $integration->forceFill([
            'last_tested_at' => now(),
            'last_test_result' => mb_substr($result['message'], 0, 500),
            'status' => $result['ok']
                ? ($integration->status === 'production_verified' ? 'production_verified' : ($integration->mode === 'live' ? 'configured' : 'sandbox_tested'))
                : ($integration->credentials ? 'adapter_ready' : 'unconfigured'),
            'last_error' => $result['ok'] ? $integration->last_error : mb_substr($result['message'], 0, 1000),
            'last_error_at' => $result['ok'] ? $integration->last_error_at : now(),
        ])->save();
        $this->audit->log('integration.tested', $integration, ['ok' => $result['ok']]);

        return $result;
    }

    /** @return array{ok: bool, message: string} */
    private function run(Integration $integration): array
    {
        if (! $integration->driver) {
            return ['ok' => false, 'message' => 'Alege mai întâi furnizorul.'];
        }
        $credentials = $integration->decryptedCredentials();

        return match ($integration->key.':'.$integration->driver) {
            'email:smtp', 'email:brevo', 'sms:twilio' => $this->normalize($this->providers->make($integration->key, $integration->driver, $credentials, (array) $integration->options)->test()),
            'payments:stripe' => $this->http(
                Http::withToken((string) ($credentials['secret_key'] ?? ''))->timeout(10)->get('https://api.stripe.com/v1/balance'),
                fn ($json) => 'Conexiune Stripe reușită ('.(str_starts_with((string) ($credentials['secret_key'] ?? ''), 'sk_live') ? 'cont live' : 'cont de test').').'
            ),
            'ai:anthropic' => $this->http(
                Http::withHeaders(['x-api-key' => (string) ($credentials['api_key'] ?? ''), 'anthropic-version' => '2023-06-01'])->timeout(10)->get('https://api.anthropic.com/v1/models', ['limit' => 1]),
                fn ($json) => 'Cheia API este validă.'
            ),
            'efactura:anaf' => app(AnafClient::class)->connectionStatus($integration),
            'fiscal:agent' => $this->fiscalAgents(),
            default => ['ok' => false, 'message' => 'Nu există test pentru acest furnizor.'],
        };
    }

    private function normalize(array $result): array
    {
        return ['ok' => (bool) ($result['ok'] ?? false), 'message' => (string) ($result['message'] ?? ($result['ok'] ?? false ? 'Conexiune reușită.' : 'Test eșuat.'))];
    }

    private function http($response, callable $success): array
    {
        if ($response->successful()) {
            return ['ok' => true, 'message' => $success($response->json())];
        }

        return ['ok' => false, 'message' => 'Furnizorul a răspuns cu HTTP '.$response->status().': '.mb_substr((string) ($response->json('error.message') ?? $response->body()), 0, 200)];
    }

    private function fiscalAgents(): array
    {
        $online = FiscalDevice::query()->where('is_active', true)->where('last_seen_at', '>=', now()->subMinutes(5))->count();

        return $online > 0
            ? ['ok' => true, 'message' => "{$online} agent(i) fiscal(i) conectat(i) în ultimele 5 minute."]
            : ['ok' => false, 'message' => 'Niciun agent fiscal conectat în ultimele 5 minute.'];
    }
}
