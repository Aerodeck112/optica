<?php

namespace App\Domain\Messaging;

use App\Models\Appointment;
use App\Models\Location;
use App\Models\Message;
use App\Models\MessageRule;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Models\Patient;
use App\Support\Settings;
use App\Support\Ui\SiteUrl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Turns an event into queued messages (COM-02, COM-04): the rule for the type and location decides
 * the channels; the first usable one is chosen, the others are kept for fallback (COM-05).
 * Nothing is sent here – the sender re-checks everything right before sending.
 */
class MessageDispatcher
{
    public function __construct(private readonly ChannelRegistry $channels) {}

    public function rule(string $type, ?int $locationId): ?MessageRule
    {
        return MessageRule::where('type', $type)
            ->where(fn ($q) => $q->whereNull('location_id')->when($locationId, fn ($q) => $q->orWhere('location_id', $locationId)))
            ->orderByRaw('location_id is null')->first();
    }

    public function template(string $type, string $channel, string $locale, ?int $locationId): ?MessageTemplate
    {
        return MessageTemplate::where('type', $type)->where('channel', $channel)->where('locale', $locale)
            ->where(fn ($q) => $q->whereNull('location_id')->when($locationId, fn ($q) => $q->orWhere('location_id', $locationId)))
            ->orderByRaw('location_id is null')->first();
    }

    public function forAppointment(Appointment $appointment, string $type, ?CarbonImmutable $sendAfter = null): ?Message
    {
        $appointment->loadMissing(['patient', 'location', 'specialist', 'service']);
        $patient = $appointment->patient;
        $locale = $appointment->locale ?: ($patient?->locale ?? 'ro');
        $tz = config('optica.clinic_timezone');
        $start = CarbonImmutable::instance($appointment->starts_at)->setTimezone($tz);
        $vars = [
            'patient_name' => $patient?->fullName() ?? (string) $appointment->contact_name,
            'date' => $start->format('d.m.Y'),
            'time' => $start->format('H:i'),
            'location' => $appointment->location?->publicValue('name', $locale) ?? $appointment->location?->name($locale),
            'location_address' => $appointment->location?->address,
            'location_phone' => $appointment->location?->phone,
            'specialist' => $appointment->specialist?->display_name,
            'service' => $appointment->service?->name($locale),
            'instructions' => $appointment->service?->publicValue('preparation', $locale),
            'manage_link' => $this->manageLink($appointment, $locale),
            'booking_link' => SiteUrl::route('booking', [], $locale),
        ];

        return $this->queue($type, $appointment->location_id, $locale, $vars, [
            'patient_id' => $patient?->id,
            'appointment_id' => $appointment->id,
            'context_starts_at' => $appointment->starts_at,
        ], $patient?->phone ?? $appointment->contact_phone, $patient?->email ?? $appointment->contact_email,
            $type.':appointment:'.$appointment->id.':'.$start->timestamp, $sendAfter);
    }

    public function forOrder(Order $order, string $type): ?Message
    {
        $order->loadMissing(['patient', 'location']);
        $locale = $order->patient->locale ?? 'ro';

        return $this->queue($type, $order->location_id, $locale, [
            'patient_name' => $order->patient->fullName(),
            'order_number' => $order->number,
            'location' => $order->location?->publicValue('name', $locale) ?? $order->location?->name($locale),
            'location_address' => $order->location?->address,
            'location_phone' => $order->location?->phone,
        ], ['patient_id' => $order->patient_id, 'order_id' => $order->id], $order->patient->phone, $order->patient->email, $type.':order:'.$order->id);
    }

    /** Recall at a date set by authorised staff (COM-04); the message carries no clinical detail. */
    public function recall(Patient $patient, Location $location, CarbonImmutable $sendAt, ?int $userId): ?Message
    {
        $locale = $patient->locale ?? 'ro';

        return $this->queue('recall', $location->id, $locale, [
            'patient_name' => $patient->fullName(),
            'location' => $location->publicValue('name', $locale) ?? $location->name($locale),
            'location_phone' => $location->phone,
            'booking_link' => SiteUrl::route('booking', [], $locale),
        ], ['patient_id' => $patient->id, 'created_by' => $userId], $patient->phone, $patient->email, 'recall:patient:'.$patient->id.':'.$sendAt->format('Ymd'), $sendAt);
    }

    /**
     * @param  array<string, mixed>  $links  patient_id, appointment_id, order_id, context_starts_at, created_by
     */
    private function queue(string $type, ?int $locationId, string $locale, array $vars, array $links, ?string $phone, ?string $email, string $key, ?CarbonImmutable $sendAfter = null): ?Message
    {
        $rule = $this->rule($type, $locationId);
        if (! $rule || ! $rule->enabled || ! $rule->channels) {
            return null;
        }
        if (Message::where('idempotency_key', 'like', $key.':%')->exists()) {
            return null;
        }

        $reasons = [];
        foreach ($rule->channels as $channel) {
            $built = $this->build($type, $channel, $locationId, $locale, $vars, $phone, $email, $reasons);
            if ($built) {
                return Message::create($built + $links + [
                    'uuid' => (string) Str::uuid(),
                    'type' => $type,
                    'category' => 'operational',
                    'location_id' => $locationId,
                    'locale' => $locale,
                    'send_after' => $sendAfter ?? now(),
                    'idempotency_key' => $key.':'.$channel,
                ]);
            }
        }

        // Nothing could be sent: the reason stays visible in the log instead of a silent gap.
        return Message::create($links + [
            'uuid' => (string) Str::uuid(),
            'type' => $type,
            'category' => 'operational',
            'channel' => $rule->channels[0],
            'location_id' => $locationId,
            'locale' => $locale,
            'recipient' => '',
            'body' => '',
            'status' => 'skipped',
            'status_reason' => implode(' · ', array_unique($reasons)),
            'send_after' => now(),
            'idempotency_key' => $key.':skipped',
        ]);
    }

    /** Message attributes for one channel, or null with the reason added to $reasons. */
    public function build(string $type, string $channel, ?int $locationId, string $locale, array $vars, ?string $phone, ?string $email, array &$reasons): ?array
    {
        $account = $this->channels->accountFor($channel, $locationId);
        if (! $account) {
            $reasons[] = __('messages.reasons.not_configured', ['channel' => __('messages.channels.'.$channel)], 'ro');

            return null;
        }
        $template = $this->template($type, $channel, $locale, $locationId) ?? $this->template($type, $channel, 'ro', $locationId);
        if (! $template || ! $template->isUsable()) {
            $reasons[] = __('messages.reasons.no_template', ['channel' => __('messages.channels.'.$channel)], 'ro');

            return null;
        }
        $recipient = Contacts::forChannel($channel, $phone, $email);
        if (! $recipient) {
            $reasons[] = __('messages.reasons.no_contact', ['channel' => __('messages.channels.'.$channel)], 'ro');

            return null;
        }

        $body = TemplateRenderer::render($template->body, $vars);
        if ($channel === 'sms' && $template->ascii_only) {
            $body = TemplateRenderer::ascii($body);
        }
        $segments = $channel === 'sms' ? SmsSegments::analyse($body)['segments'] : null;

        return [
            'channel' => $channel,
            'account_id' => $account->id,
            'locale' => $template->locale,
            'recipient' => $recipient,
            'subject' => $template->subject ? TemplateRenderer::render($template->subject, $vars) : null,
            'body' => $body,
            'vars' => array_intersect_key($vars, array_flip(MessageTypes::variables($type))),
            'segments' => $segments,
            'cost_estimated_cents' => $account->estimated_unit_cost_cents !== null ? $account->estimated_unit_cost_cents * ($segments ?? 1) : null,
        ];
    }

    /** The link in the message lets the patient cancel or move the appointment without an account. */
    private function manageLink(Appointment $appointment, string $locale): ?string
    {
        if (! $appointment->manage_token_encrypted) {
            $token = bin2hex(random_bytes(24));
            $appointment->forceFill(['manage_token_hash' => hash('sha256', $token), 'manage_token_encrypted' => encrypt($token)])->saveQuietly();
        } else {
            $token = decrypt($appointment->manage_token_encrypted);
        }

        return SiteUrl::route('booking.manage', ['token' => $token], $locale);
    }
}
