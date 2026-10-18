<?php

namespace App\Domain\Messaging\Channels;

use App\Domain\Messaging\TemplateRenderer;
use App\Models\ChannelAccount;
use App\Models\Message;
use App\Models\MessageTemplate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp through the official WhatsApp Business Cloud API (WAP-01). Business-initiated messages
 * use templates approved by Meta; the placeholders of our template, in order, fill {{1}}, {{2}}…
 * Nothing is automated through WhatsApp Web.
 */
class WhatsAppCloudAdapter implements ChannelAdapter
{
    public function requiredCredentials(): array
    {
        return ['phone_number_id', 'access_token', 'app_secret', 'verify_token'];
    }

    public function send(Message $message, ChannelAccount $account): SendResult
    {
        $template = MessageTemplate::where('type', $message->type)->where('channel', 'whatsapp')->where('locale', $message->locale)
            ->where(fn ($q) => $q->where('location_id', $message->location_id)->orWhereNull('location_id'))
            ->orderByRaw('location_id is null')->first();
        if (! $template || ! $template->isUsable()) {
            throw SendFailure::permanent('WhatsApp template not approved');
        }

        $values = (array) ($message->vars ?? []);
        $parameters = array_map(fn ($name) => ['type' => 'text', 'text' => (string) ($values[$name] ?? '')], TemplateRenderer::placeholders($template->body));
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => ltrim($message->recipient, '+'),
            'type' => 'template',
            'template' => [
                'name' => $template->provider_template,
                'language' => ['code' => $message->locale],
                'components' => $parameters ? [['type' => 'body', 'parameters' => $parameters]] : [],
            ],
        ];
        $version = $account->credential('api_version') ?? 'v21.0';

        try {
            $response = Http::withToken((string) $account->credential('access_token'))->timeout(20)
                ->post('https://graph.facebook.com/'.$version.'/'.rawurlencode((string) $account->credential('phone_number_id')).'/messages', $payload);
        } catch (ConnectionException $e) {
            throw SendFailure::uncertain($e->getMessage());
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw SendFailure::transient('HTTP '.$response->status().' '.$response->json('error.message'));
        }
        if (! $response->successful()) {
            throw SendFailure::permanent('WhatsApp '.$response->json('error.code').': '.$response->json('error.message'));
        }

        return new SendResult('accepted', (string) $response->json('messages.0.id'));
    }
}
