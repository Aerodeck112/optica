<?php

namespace App\Domain\Messaging\Channels;

use App\Models\ChannelAccount;
use App\Models\Message;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * SMS through the Twilio Messages API. Delivery states arrive on the signed status callback.
 */
class TwilioSmsAdapter implements ChannelAdapter
{
    public function requiredCredentials(): array
    {
        return ['account_sid', 'auth_token'];
    }

    public function send(Message $message, ChannelAccount $account): SendResult
    {
        $sid = (string) $account->credential('account_sid');
        $params = [
            'To' => $message->recipient,
            'Body' => $message->body,
            'StatusCallback' => route('webhooks.twilio', $account),
        ];
        // Either a Messaging Service or a sender (number or alphanumeric ID) identifies the clinic.
        if ($service = $account->credential('messaging_service_sid')) {
            $params['MessagingServiceSid'] = $service;
        } else {
            $params['From'] = (string) $account->sender;
        }

        try {
            $response = Http::asForm()->timeout(20)->withBasicAuth($sid, (string) $account->credential('auth_token'))
                ->post('https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($sid).'/Messages.json', $params);
        } catch (ConnectionException $e) {
            // The request may have been received: retrying could send the SMS twice.
            throw SendFailure::uncertain($e->getMessage());
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw SendFailure::transient('HTTP '.$response->status().' '.$response->json('message'));
        }
        if (! $response->successful()) {
            throw SendFailure::permanent('Twilio '.$response->json('code').': '.$response->json('message'));
        }

        $price = $response->json('price');

        return new SendResult('accepted', (string) $response->json('sid'), (int) $response->json('num_segments') ?: null,
            $price !== null ? (int) round(abs((float) $price) * 100) : null);
    }
}
