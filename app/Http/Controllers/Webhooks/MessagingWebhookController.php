<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Messaging\Contacts;
use App\Domain\Messaging\MessageSender;
use App\Http\Controllers\Controller;
use App\Models\ChannelAccount;
use App\Support\Consent\ConsentRecorder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provider callbacks (COM-03). Every request is authenticated with the provider's signature before
 * anything is read; repeated events are ignored; replies such as "STOP" record the withdrawal (MKT-02).
 */
class MessagingWebhookController extends Controller
{
    private const STOP_WORDS = ['stop', 'stopp', 'dezabonare', 'leiratkozas', 'leiratkozás', 'unsubscribe'];

    public function __construct(private readonly MessageSender $sender, private readonly ConsentRecorder $consents) {}

    /** Meta's subscription check: echo the challenge only for our verify token. */
    public function whatsappVerify(Request $request, ChannelAccount $account): Response
    {
        abort_unless($account->driver === 'whatsapp_cloud', 404);
        $token = (string) $account->credential('verify_token');
        if ($request->query('hub_mode') !== 'subscribe' || $token === '' || ! hash_equals($token, (string) $request->query('hub_verify_token'))) {
            abort(403);
        }

        return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
    }

    public function whatsapp(Request $request, ChannelAccount $account): Response
    {
        abort_unless($account->driver === 'whatsapp_cloud', 404);
        $secret = (string) $account->credential('app_secret');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        if ($secret === '' || ! hash_equals($expected, (string) $request->header('X-Hub-Signature-256'))) {
            abort(403);
        }

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];
                foreach ((array) ($value['statuses'] ?? []) as $status) {
                    $mapped = match ($status['status'] ?? '') {
                        'sent' => 'sent',
                        // "Read" implies delivered; reading is never used to decide anything else.
                        'delivered', 'read' => 'delivered',
                        'failed' => 'failed',
                        default => null,
                    };
                    if ($mapped && ! empty($status['id'])) {
                        $detail = isset($status['errors'][0]) ? ($status['errors'][0]['code'] ?? '').' '.($status['errors'][0]['title'] ?? '') : null;
                        $this->sender->applyProviderStatus('whatsapp', 'wa:'.$status['id'].':'.$status['status'].':'.($status['timestamp'] ?? ''), $status['id'], $mapped, $detail);
                    }
                }
                foreach ((array) ($value['messages'] ?? []) as $incoming) {
                    $text = mb_strtolower(trim((string) ($incoming['text']['body'] ?? $incoming['button']['text'] ?? '')));
                    if (in_array($text, self::STOP_WORDS, true) && ($phone = Contacts::phone('+'.ltrim((string) ($incoming['from'] ?? ''), '+')))) {
                        $this->consents->withdrawMarketing(null, $phone, null, 'whatsapp', 'whatsapp_reply');
                    }
                }
            }
        }

        return response()->noContent();
    }

    public function twilio(Request $request, ChannelAccount $account): Response
    {
        abort_unless($account->driver === 'twilio', 404);
        if (! $this->validTwilioSignature($request, (string) $account->credential('auth_token'))) {
            abort(403);
        }

        $sid = (string) $request->input('MessageSid', $request->input('SmsSid'));
        $status = (string) $request->input('MessageStatus', $request->input('SmsStatus'));
        $mapped = match ($status) {
            'queued', 'accepted', 'scheduled' => 'accepted',
            'sending', 'sent' => 'sent',
            'delivered' => 'delivered',
            'undelivered', 'failed' => 'failed',
            default => null,
        };
        if ($sid !== '' && $mapped && $status !== 'received') {
            $price = $request->input('Price');
            $this->sender->applyProviderStatus('twilio', 'tw:'.$sid.':'.$status, $sid, $mapped, $request->input('ErrorCode') ? 'Twilio '.$request->input('ErrorCode') : null,
                $price !== null && $price !== '' ? (int) round(abs((float) $price) * 100) : null);
        }
        // Incoming reply.
        $body = mb_strtolower(trim((string) $request->input('Body', '')));
        if ($body !== '' && in_array($body, self::STOP_WORDS, true) && ($phone = Contacts::phone((string) $request->input('From')))) {
            $this->consents->withdrawMarketing(null, $phone, null, 'sms', 'sms_reply');
        }

        return response('<Response></Response>', 200, ['Content-Type' => 'text/xml']);
    }

    /** Twilio: base64(HMAC-SHA1(auth token, full URL + POST parameters sorted by name, name+value)). */
    private function validTwilioSignature(Request $request, string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $params = $request->post();
        ksort($params);
        $data = $request->fullUrl();
        foreach ($params as $key => $value) {
            $data .= $key.(is_array($value) ? implode('', $value) : $value);
        }

        return hash_equals(base64_encode(hash_hmac('sha1', $data, $token, true)), (string) $request->header('X-Twilio-Signature'));
    }
}
