<?php

namespace App\Modules\Finance\Services\Payments;

use App\Modules\Finance\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stripe Checkout prin API-ul REST (fără SDK suplimentar). Semnătura webhook-ului este verificată cu
 * secretul „whsec_…” (HMAC-SHA256, toleranță 5 minute); cererile de creare folosesc chei de idempotență.
 */
class StripeGateway implements PaymentGateway
{
    private const API = 'https://api.stripe.com/v1';

    public function __construct(private readonly array $credentials) {}

    public function name(): string
    {
        return 'stripe';
    }

    private function http()
    {
        return Http::withToken((string) ($this->credentials['secret_key'] ?? ''))->asForm()->timeout(20);
    }

    public function createCheckout(Payment $payment, string $description, string $successUrl, string $cancelUrl): array
    {
        $expires = now()->addMinutes(35);
        $response = $this->http()->withHeaders(['Idempotency-Key' => 'checkout-'.$payment->uuid])->post(self::API.'/checkout/sessions', [
            'mode' => 'payment',
            'client_reference_id' => $payment->uuid,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'expires_at' => $expires->getTimestamp(),
            'locale' => 'ro',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => ['currency' => strtolower($payment->currency), 'unit_amount' => $payment->amount_minor, 'product_data' => ['name' => mb_substr($description, 0, 200)]],
            ]],
            'metadata' => ['payment_uuid' => $payment->uuid],
            'payment_intent_data' => ['metadata' => ['payment_uuid' => $payment->uuid]],
        ]);
        if (! $response->successful() || ! $response->json('url')) {
            throw new \RuntimeException('Procesatorul de plăți nu a creat sesiunea (HTTP '.$response->status().').');
        }

        return ['url' => (string) $response->json('url'), 'reference' => (string) $response->json('id'), 'expires_at' => $expires];
    }

    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) ($this->credentials['webhook_secret'] ?? '');
        $header = (string) $request->header('Stripe-Signature');
        if ($secret === '' || $header === '') {
            return false;
        }
        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$k, $v] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[$k][] = $v;
        }
        $timestamp = (int) ($parts['t'][0] ?? 0);
        if (abs(time() - $timestamp) > 300) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        foreach ($parts['v1'] ?? [] as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function parseWebhook(Request $request): array
    {
        $event = json_decode($request->getContent(), true) ?: [];
        $object = (array) ($event['data']['object'] ?? []);
        $type = (string) ($event['type'] ?? '');
        $status = match ($type) {
            'checkout.session.completed' => ($object['payment_status'] ?? '') === 'paid' ? 'succeeded' : 'ignored',
            'checkout.session.async_payment_succeeded' => 'succeeded',
            'checkout.session.async_payment_failed' => 'failed',
            'checkout.session.expired' => 'expired',
            'charge.refunded' => 'refunded',
            default => 'ignored',
        };

        return [[
            'event_id' => (string) ($event['id'] ?? ''),
            'type' => $type,
            'reference' => str_starts_with($type, 'checkout.') ? ($object['id'] ?? null) : null,
            'payment_uuid' => $object['client_reference_id'] ?? ($object['metadata']['payment_uuid'] ?? null),
            'status' => $status,
            'amount_minor' => isset($object['amount_total']) ? (int) $object['amount_total'] : (isset($object['amount_refunded']) ? (int) $object['amount_refunded'] : null),
            'data' => array_filter(['payment_intent' => $object['payment_intent'] ?? null, 'currency' => $object['currency'] ?? null]),
        ]];
    }

    public function refund(Payment $payment, int $amountMinor, string $idempotencyKey): array
    {
        $intent = $payment->provider_data['payment_intent'] ?? null;
        if (! $intent) {
            return ['ok' => false, 'reference' => null, 'error' => 'Plata nu are identificatorul procesatorului (payment_intent).'];
        }
        $response = $this->http()->withHeaders(['Idempotency-Key' => $idempotencyKey])->post(self::API.'/refunds', ['payment_intent' => $intent, 'amount' => $amountMinor]);

        return $response->successful()
            ? ['ok' => true, 'reference' => (string) $response->json('id'), 'error' => null]
            : ['ok' => false, 'reference' => null, 'error' => (string) ($response->json('error.message') ?? 'HTTP '.$response->status())];
    }
}
