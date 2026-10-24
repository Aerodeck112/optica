<?php

namespace App\Modules\Finance\Services\Payments;

use App\Modules\Core\Models\WebhookEvent;
use App\Modules\Finance\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook-urile procesatorului: semnătura se verifică înainte de orice; fiecare eveniment este înregistrat
 * o singură dată (furnizor + id). Un eveniment primit dar neprocesat (eroare) se reprocesează la retrimitere,
 * iar efectul asupra plății rămâne idempotent.
 */
class PaymentWebhookProcessor
{
    public function __construct(private readonly PaymentService $payments) {}

    public function handle(PaymentGateway $gateway, Request $request): JsonResponse
    {
        if (! $gateway->verifyWebhook($request)) {
            WebhookEvent::query()->create([
                'provider' => $gateway->name(),
                'event_id' => 'invalid:'.sha1($request->getContent().microtime()),
                'signature_valid' => false,
                'status' => 'ignored',
                'error' => 'Semnătură invalidă',
            ]);

            return response()->json(['error' => 'invalid_signature'], 400);
        }

        foreach ($gateway->parseWebhook($request) as $event) {
            if ($event['event_id'] === '') {
                continue;
            }
            $record = $this->claim($gateway->name(), $event);
            if (! $record) {
                continue;
            }

            try {
                $outcome = $this->apply($event);
                $record->forceFill(['status' => $outcome === 'ignored' ? 'ignored' : 'processed', 'processed_at' => now(), 'error' => null])->save();
            } catch (PaymentException $e) {
                // nepotrivire de sumă sau plată necunoscută: nu reîncercăm automat, rămâne pentru verificare manuală
                $record->forceFill(['status' => 'failed', 'error' => $e->getMessage(), 'processed_at' => now()])->save();
            } catch (\Throwable $e) {
                $record->increment('attempts', 1, ['status' => 'received', 'error' => mb_substr($e->getMessage(), 0, 500)]);
                report($e);

                return response()->json(['error' => 'retry'], 500);
            }
        }

        return response()->json(['ok' => true]);
    }

    /** Înregistrează evenimentul; null dacă a fost deja procesat (retrimitere). */
    private function claim(string $provider, array $event): ?WebhookEvent
    {
        $attributes = [
            'provider' => $provider,
            'event_id' => $event['event_id'],
            'type' => mb_substr($event['type'], 0, 120),
            'signature_valid' => true,
            'status' => 'received',
            'payload' => ['payment_uuid' => $event['payment_uuid'], 'reference' => $event['reference'], 'status' => $event['status'], 'amount_minor' => $event['amount_minor']],
        ];
        try {
            return WebhookEvent::query()->create($attributes);
        } catch (QueryException) {
            $existing = WebhookEvent::query()->where('provider', $provider)->where('event_id', $event['event_id'])->first();

            return $existing && $existing->status === 'received' ? $existing : null;
        }
    }

    private function apply(array $event): string
    {
        if ($event['status'] === 'ignored' || $event['status'] === 'refunded') {
            return 'ignored'; // rambursările sunt inițiate și urmărite din aplicație
        }

        $payment = Payment::query()
            ->when($event['payment_uuid'], fn ($query) => $query->where('uuid', $event['payment_uuid']), fn ($query) => $query->where('provider_reference', $event['reference']))
            ->first();
        if (! $payment) {
            throw new PaymentException('unknown', 'Plată necunoscută în evenimentul procesatorului.');
        }

        match ($event['status']) {
            'succeeded' => $this->payments->markSucceeded($payment, $event['amount_minor'], $event['data']['currency'] ?? null, array_filter(['payment_intent' => $event['data']['payment_intent'] ?? null])),
            'failed' => $this->payments->markClosed($payment, 'failed'),
            'expired' => $this->payments->markClosed($payment, 'cancelled'),
            default => null,
        };

        return 'processed';
    }
}
