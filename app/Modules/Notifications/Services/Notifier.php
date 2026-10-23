<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\ConsentService;
use App\Modules\Notifications\Jobs\DeliverMessage;
use App\Modules\Notifications\Models\MessageLog;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Support\ContactNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Punctul unic prin care pleacă mesajele. Garantează:
 *  - fără duplicate (cheie de idempotență unică în message_logs);
 *  - mesajele de marketing doar cu consimțământ pe canal și fără excludere, verificate și la livrare;
 *  - mesajele de serviciu separate de cele promoționale;
 *  - în afara producției, mesajele către destinatari care nu sunt de test nu pleacă (sandbox).
 */
class Notifier
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly VariableBuilder $variables,
        private readonly ConsentService $consents,
    ) {}

    public function sendToCustomer(Customer $customer, string $templateKey, string $channel, array $context = [], string $category = 'service', ?string $idempotencyKey = null, ?Booking $booking = null, ?int $campaignId = null): ?MessageLog
    {
        $address = $channel === 'sms' ? $customer->phone : $customer->email;
        if (! $address) {
            return null;
        }

        return $this->send($channel, $address, $templateKey, $context + ['customer' => $customer, 'booking' => $booking ?? ($context['booking'] ?? null)], $category, $idempotencyKey, $customer, $booking, $campaignId);
    }

    public function sendToAddress(string $channel, string $address, string $templateKey, array $context = [], string $category = 'service', ?string $idempotencyKey = null): ?MessageLog
    {
        return $this->send($channel, $address, $templateKey, $context, $category, $idempotencyKey);
    }

    /** Mesaj cu conținut deja definit (campanii, mesaje manuale). */
    public function sendRaw(string $channel, string $address, ?string $subject, string $body, string $category, ?Customer $customer, string $idempotencyKey, ?int $campaignId = null, array $context = []): ?MessageLog
    {
        return $this->dispatch($channel, $address, null, $subject, $body, $context + ['customer' => $customer], $category, $idempotencyKey, $customer, null, $campaignId);
    }

    private function send(string $channel, string $address, string $templateKey, array $context, string $category, ?string $idempotencyKey, ?Customer $customer = null, ?Booking $booking = null, ?int $campaignId = null): ?MessageLog
    {
        $locale = $customer?->locale ?: ($booking?->locale ?: 'ro');
        $template = NotificationTemplate::query()
            ->where('key', $templateKey)->where('channel', $channel)->where('is_active', true)
            ->whereIn('locale', [$locale, 'ro'])
            ->orderByRaw('locale = ? desc', [$locale])
            ->first();

        if (! $template) {
            return null;
        }

        return $this->dispatch($channel, $address, $templateKey, $template->subject, $template->body, $context, $category, $idempotencyKey, $customer, $booking, $campaignId);
    }

    private function dispatch(string $channel, string $address, ?string $templateKey, ?string $subject, string $body, array $context, string $category, ?string $idempotencyKey, ?Customer $customer, ?Booking $booking, ?int $campaignId): ?MessageLog
    {
        $normalized = $channel === 'sms' ? ContactNormalizer::phone($address) : ContactNormalizer::email($address);
        if (! $normalized) {
            return null;
        }

        if ($idempotencyKey && ($existing = MessageLog::query()->where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }

        $vars = $this->variables->build($context + ['category' => $category, 'channel' => $channel]);
        $renderedSubject = $subject ? $this->renderer->render($subject, $vars) : null;

        $suppressed = $category === 'marketing'
            ? ($customer ? ! $this->consents->canReceiveMarketing($customer, $channel) : true)
            : $this->consents->isSuppressed($channel, $normalized, 'service');

        try {
            $log = MessageLog::query()->create([
                'uuid' => (string) Str::uuid(),
                'channel' => $channel,
                'category' => $category,
                'template_key' => $templateKey,
                'recipient_masked' => $channel === 'sms' ? ContactNormalizer::maskPhone($normalized) : ContactNormalizer::maskEmail($normalized),
                'recipient_hash' => hash('sha256', $channel.'|'.$normalized),
                'customer_id' => $customer?->id,
                'booking_id' => $booking?->id,
                'campaign_id' => $campaignId,
                'subject' => $renderedSubject,
                'status' => $suppressed ? 'suppressed' : 'queued',
                'idempotency_key' => $idempotencyKey,
                'last_error' => $suppressed ? ($category === 'marketing' ? 'Fără consimțământ activ sau adresă exclusă.' : 'Adresă exclusă (bounce/reclamație).') : null,
            ]);
        } catch (QueryException $e) {
            // cursă pe aceeași cheie de idempotență: mesajul există deja
            if ($idempotencyKey && str_contains($e->getMessage(), 'idempotency_key')) {
                return MessageLog::query()->where('idempotency_key', $idempotencyKey)->first();
            }
            throw $e;
        }

        if ($suppressed) {
            return $log;
        }

        // conținutul se păstrează doar în payload-ul job-ului (nu în jurnal), pentru minimizarea datelor
        DeliverMessage::dispatch($log->id, $normalized, [
            'subject' => $renderedSubject,
            'text' => $channel === 'sms' ? $this->renderer->render($body, $vars) : $this->renderer->plainText($body, $vars),
            'html' => $channel === 'email' ? $this->renderer->emailHtml($body, (string) $renderedSubject, $vars, $category) : null,
            'headers' => $category === 'marketing' && ! empty($vars['unsubscribe_url'])
                ? ['List-Unsubscribe' => '<'.$vars['unsubscribe_url'].'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']
                : [],
        ]);

        return $log->fresh();
    }
}
