<?php

namespace App\Modules\Notifications\Providers;

use App\Modules\Notifications\Contracts\MessageProvider;
use App\Modules\Notifications\Services\OutgoingMessage;
use App\Modules\Notifications\Services\ProviderResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Brevo (API tranzacțional v3). Webhook-urile Brevo nu sunt semnate criptografic: le autentificăm printr-un
 * secret în URL (query `key`), configurat la crearea webhook-ului în contul Brevo.
 * Stare: adaptor pregătit, testat cu răspunsuri simulate — necesită validare cu un cont real.
 */
class BrevoEmailProvider implements MessageProvider
{
    private const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    public function __construct(private readonly array $credentials, private readonly array $options) {}

    public function name(): string
    {
        return 'brevo';
    }

    public function send(OutgoingMessage $message): ProviderResult
    {
        try {
            $response = Http::withHeaders(['api-key' => (string) ($this->credentials['api_key'] ?? ''), 'accept' => 'application/json'])
                ->timeout(20)
                ->post(self::ENDPOINT, array_filter([
                    'sender' => ['email' => $this->options['from_email'] ?? config('mail.from.address'), 'name' => $this->options['from_name'] ?? config('mail.from.name')],
                    'to' => [['email' => $message->to]],
                    'subject' => $message->subject,
                    'htmlContent' => $message->html,
                    'textContent' => $message->text,
                    'headers' => $message->headers + ['X-AW-Message' => $message->uuid],
                    'tags' => [$message->category],
                ]));
        } catch (\Throwable $e) {
            return ProviderResult::failed('Conexiune eșuată: '.mb_substr($e->getMessage(), 0, 200), true);
        }

        if ($response->successful()) {
            return ProviderResult::accepted($response->json('messageId'));
        }

        return ProviderResult::failed('Brevo '.$response->status().': '.mb_substr((string) $response->json('message', $response->body()), 0, 300), $response->status() >= 500 || $response->status() === 429);
    }

    public function supportsDeliveryReports(): bool
    {
        return true;
    }

    public function verifyWebhook(Request $request): bool
    {
        $secret = (string) ($this->credentials['webhook_secret'] ?? '');

        return $secret !== '' && hash_equals($secret, (string) $request->query('key'));
    }

    public function parseWebhook(Request $request): array
    {
        $events = $request->isJson() && array_is_list($request->json()->all()) ? $request->json()->all() : [$request->json()->all()];
        $map = ['delivered' => 'delivered', 'hard_bounce' => 'bounced', 'soft_bounce' => 'soft_bounced', 'blocked' => 'failed', 'invalid_email' => 'bounced', 'spam' => 'complained', 'unsubscribed' => 'unsubscribed', 'opened' => 'opened', 'unique_opened' => 'opened', 'click' => 'clicked', 'error' => 'failed'];
        $out = [];
        foreach ($events as $event) {
            $status = $map[$event['event'] ?? ''] ?? null;
            if (! $status || empty($event['message-id'])) {
                continue;
            }
            $out[] = [
                'event_id' => (string) ($event['id'] ?? sha1(($event['message-id'] ?? '').($event['event'] ?? '').($event['ts_event'] ?? ''))),
                'message_id' => (string) $event['message-id'],
                'status' => $status,
                'reason' => $event['reason'] ?? null,
                'address' => $event['email'] ?? null,
            ];
        }

        return $out;
    }

    public function test(): array
    {
        try {
            $response = Http::withHeaders(['api-key' => (string) ($this->credentials['api_key'] ?? ''), 'accept' => 'application/json'])->timeout(10)->get('https://api.brevo.com/v3/account');

            return $response->successful()
                ? ['ok' => true, 'message' => 'Cheia API Brevo este validă.']
                : ['ok' => false, 'message' => 'Brevo a răspuns '.$response->status().'.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Conexiune eșuată: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }
}
