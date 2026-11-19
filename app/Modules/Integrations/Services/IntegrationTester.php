<?php

namespace App\Modules\Integrations\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Core\Models\Integration;
use App\Modules\Core\Settings\SettingsRepository;
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
        if ($result['ok'] && $integration->key === 'payments') {
            // plata online devine disponibilă și la rezervări (nu doar la vouchere)
            $settings = app(SettingsRepository::class);
            $methods = (array) $settings->get('booking.payment_methods', ['at_location']);
            if (! in_array('online', $methods, true)) {
                $settings->set('booking.payment_methods', [...$methods, 'online']);
                $result['message'] .= ' Plata online a fost activată și la rezervări (Setări → Rezervări → Metode de plată).';
            }
        }

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
            'payments:stripe' => $this->stripe($integration, $credentials),
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

    /**
     * Stripe complet: cheia, potrivirea cheii cu modul ales, webhook-ul înregistrat în contul Stripe (adresa și
     * evenimentele) și secretul lui. Fără webhook, plata reușește la Stripe dar rezervarea nu se confirmă în aplicație.
     *
     * @return array{ok: bool, message: string}
     */
    private function stripe(Integration $integration, array $credentials): array
    {
        $key = (string) ($credentials['secret_key'] ?? '');
        if (! preg_match('/^(sk|rk)_(live|test)_/', $key)) {
            return ['ok' => false, 'message' => 'Cheia trebuie să fie „Secret key” din Stripe (începe cu sk_live_ sau sk_test_), nu „Publishable key” (pk_…).'];
        }
        $live = str_contains($key, '_live_');
        if ($live && $integration->mode === 'sandbox') {
            return ['ok' => false, 'message' => 'Ați pus o cheie live (sk_live_…) cu modul „Sandbox / test”. Alegeți modul „Producție” sau folosiți cheia de test.'];
        }
        if (! $live && $integration->mode === 'live') {
            return ['ok' => false, 'message' => 'Ați pus o cheie de test (sk_test_…) cu modul „Producție”. Pentru plăți reale folosiți cheia sk_live_…; pentru probe alegeți „Sandbox / test”.'];
        }
        $balance = Http::withToken($key)->timeout(10)->get('https://api.stripe.com/v1/balance');
        if (! $balance->successful()) {
            return ['ok' => false, 'message' => 'Stripe a refuzat cheia (HTTP '.$balance->status().'): '.mb_substr((string) ($balance->json('error.message') ?? ''), 0, 200)];
        }
        $url = rtrim((string) config('app.url'), '/').'/api/webhooks/stripe';
        $endpoints = collect(Http::withToken($key)->timeout(10)->get('https://api.stripe.com/v1/webhook_endpoints', ['limit' => 100])->json('data') ?? []);
        $endpoint = $endpoints->first(fn ($e) => rtrim((string) ($e['url'] ?? ''), '/') === $url && ($e['status'] ?? '') === 'enabled');
        if (! $endpoint) {
            return ['ok' => false, 'message' => "Cheia este corectă, dar în Stripe nu există webhook-ul pentru {$url}. Creați-l în Stripe → Developers → Webhooks (vezi ghidul), apoi copiați aici „Signing secret” (whsec_…)."];
        }
        $needed = ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired'];
        $events = (array) ($endpoint['enabled_events'] ?? []);
        $missing = in_array('*', $events, true) ? [] : array_values(array_diff($needed, $events));
        if ($missing) {
            return ['ok' => false, 'message' => 'Webhook-ul din Stripe nu primește evenimentele: '.implode(', ', $missing).'. Adăugați-le la webhook (Stripe → Developers → Webhooks).'];
        }
        if (! str_starts_with((string) ($credentials['webhook_secret'] ?? ''), 'whsec_')) {
            return ['ok' => false, 'message' => 'Lipsește „Signing secret” al webhook-ului (whsec_…): fără el plățile nu se confirmă automat. Îl găsiți în Stripe → Developers → Webhooks → webhook-ul vostru → Reveal.'];
        }

        return ['ok' => true, 'message' => 'Stripe este gata: cheia, webhook-ul și secretul sunt corecte ('.($live ? 'plăți reale' : 'mod de test').').'];
    }

    private function fiscalAgents(): array
    {
        $online = FiscalDevice::query()->where('is_active', true)->where('last_seen_at', '>=', now()->subMinutes(5))->count();

        return $online > 0
            ? ['ok' => true, 'message' => "{$online} agent(i) fiscal(i) conectat(i) în ultimele 5 minute."]
            : ['ok' => false, 'message' => 'Niciun agent fiscal conectat în ultimele 5 minute.'];
    }
}
