<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\WebhookEvent;
use App\Modules\Finance\Services\Payments\PaymentGatewayFactory;
use App\Modules\Finance\Services\Payments\PaymentWebhookProcessor;
use App\Modules\Notifications\Services\DeliveryEventProcessor;
use App\Modules\Notifications\Services\ProviderFactory;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Punct unic pentru webhook-uri. Fiecare furnizor își verifică semnătura; evenimentele se procesează o singură dată
 * (unicitate pe furnizor + id eveniment), deci retrimiterile nu dublează efectele.
 */
class WebhookController extends Controller
{
    public function handle(Request $request, string $provider): JsonResponse
    {
        if ($gateway = app(PaymentGatewayFactory::class)->byName($provider)) {
            return app(PaymentWebhookProcessor::class)->handle($gateway, $request);
        }

        $messaging = app(ProviderFactory::class)->byName($provider);
        if (! $messaging || ! $messaging->supportsDeliveryReports()) {
            return response()->json(['error' => 'unknown_provider'], 404);
        }
        if (! $messaging->verifyWebhook($request)) {
            WebhookEvent::query()->create([
                'provider' => $provider,
                'event_id' => 'invalid:'.sha1($request->getContent().microtime()),
                'signature_valid' => false,
                'status' => 'ignored',
                'error' => 'Semnătură invalidă',
            ]);

            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $processor = app(DeliveryEventProcessor::class);
        foreach ($messaging->parseWebhook($request) as $event) {
            try {
                $record = WebhookEvent::query()->create([
                    'provider' => $provider,
                    'event_id' => $event['event_id'],
                    'type' => $event['status'],
                    'signature_valid' => true,
                    'status' => 'received',
                    'payload' => ['message_id' => $event['message_id'], 'status' => $event['status'], 'reason' => $event['reason']],
                ]);
            } catch (QueryException) {
                continue; // eveniment deja procesat
            }
            $processor->apply($provider, $event);
            $record->forceFill(['status' => 'processed', 'processed_at' => now()])->save();
        }

        return response()->json(['ok' => true]);
    }
}
