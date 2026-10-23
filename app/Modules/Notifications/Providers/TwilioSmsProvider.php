<?php

namespace App\Modules\Notifications\Providers;

use App\Modules\Notifications\Contracts\MessageProvider;
use App\Modules\Notifications\Services\OutgoingMessage;
use App\Modules\Notifications\Services\ProviderResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Twilio Programmable Messaging. Rapoartele de livrare vin pe StatusCallback și sunt verificate
 * cu semnătura X-Twilio-Signature (HMAC-SHA1 peste URL + parametri sortați, cheie = Auth Token).
 * Stare: adaptor pregătit, testat cu semnături calculate local — necesită validare cu un cont real.
 */
class TwilioSmsProvider implements MessageProvider
{
    public function __construct(private readonly array $credentials, private readonly array $options) {}

    public function name(): string
    {
        return 'twilio';
    }

    public function send(OutgoingMessage $message): ProviderResult
    {
        $sid = (string) ($this->credentials['account_sid'] ?? '');
        try {
            $response = Http::asForm()
                ->withBasicAuth($sid, (string) ($this->credentials['auth_token'] ?? ''))
                ->timeout(20)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", array_filter([
                    'To' => $message->to,
                    'From' => $this->options['from'] ?? null,
                    'MessagingServiceSid' => $this->options['messaging_service_sid'] ?? null,
                    'Body' => $message->text,
                    'StatusCallback' => route('webhooks.handle', ['provider' => 'twilio']),
                ]));
        } catch (\Throwable $e) {
            return ProviderResult::failed('Conexiune eșuată: '.mb_substr($e->getMessage(), 0, 200), true);
        }

        if ($response->successful()) {
            return ProviderResult::accepted($response->json('sid'), null, (int) $response->json('num_segments') ?: null);
        }

        return ProviderResult::failed('Twilio '.$response->status().': '.mb_substr((string) $response->json('message', ''), 0, 300), $response->status() >= 500 || $response->status() === 429);
    }

    public function supportsDeliveryReports(): bool
    {
        return true;
    }

    public function verifyWebhook(Request $request): bool
    {
        $token = (string) ($this->credentials['auth_token'] ?? '');
        $signature = (string) $request->header('X-Twilio-Signature');
        if ($token === '' || $signature === '') {
            return false;
        }

        return hash_equals(self::signature($token, $request->fullUrl(), $request->post()), $signature);
    }

    public static function signature(string $token, string $url, array $params): string
    {
        ksort($params);
        $data = $url;
        foreach ($params as $key => $value) {
            $data .= $key.$value;
        }

        return base64_encode(hash_hmac('sha1', $data, $token, true));
    }

    public function parseWebhook(Request $request): array
    {
        $status = match ($request->input('MessageStatus')) {
            'delivered' => 'delivered',
            'undelivered', 'failed' => 'failed',
            default => null,
        };
        if (! $status) {
            return [];
        }

        return [[
            'event_id' => $request->input('MessageSid').':'.$request->input('MessageStatus'),
            'message_id' => (string) $request->input('MessageSid'),
            'status' => $status,
            'reason' => $request->input('ErrorCode') ? 'Twilio error '.$request->input('ErrorCode') : null,
            'address' => $request->input('To'),
        ]];
    }

    public function test(): array
    {
        $sid = (string) ($this->credentials['account_sid'] ?? '');
        try {
            $response = Http::withBasicAuth($sid, (string) ($this->credentials['auth_token'] ?? ''))->timeout(10)->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");

            return $response->successful() ? ['ok' => true, 'message' => 'Contul Twilio este accesibil.'] : ['ok' => false, 'message' => 'Twilio a răspuns '.$response->status().'.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Conexiune eșuată: '.mb_substr($e->getMessage(), 0, 200)];
        }
    }
}
