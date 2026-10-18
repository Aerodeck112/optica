<?php

namespace App\Domain\Messaging;

use App\Domain\Messaging\Channels\SendFailure;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\ChannelAccount;
use App\Models\Message;
use App\Models\MessageEvent;
use App\Support\Consent\ConsentRecorder;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sends due messages from the cron-driven queue (D-006). Each message is claimed atomically,
 * re-checked right before sending (appointment still valid, consent still given, quiet hours,
 * provider limits), and its outcome is stored honestly (COM-02, COM-03, MKT-02).
 */
class MessageSender
{
    /** Minutes to wait before retrying after a temporary error. */
    public const BACKOFF = [1, 5, 15, 60];

    public function __construct(
        private readonly ChannelRegistry $channels,
        private readonly ConsentRecorder $consents,
        private readonly MessageDispatcher $dispatcher,
    ) {}

    /** @return int messages processed */
    public function processDue(int $limit = 50, int $seconds = 45): int
    {
        $started = microtime(true);
        $done = 0;
        $ids = Message::where('status', 'pending')->where('send_after', '<=', now())
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('send_after')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            if (microtime(true) - $started > $seconds) {
                break;
            }
            $this->process($id);
            $done++;
        }

        return $done;
    }

    public function process(int $id): void
    {
        // Claim: only one worker can move a message from pending to sending.
        $claimed = Message::whereKey($id)->where('status', 'pending')->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if ($claimed === 0) {
            return;
        }
        $message = Message::with(['account', 'appointment', 'campaign'])->findOrFail($id);

        if ($reason = $this->staleReason($message)) {
            $this->finish($message, 'skipped', $reason);

            return;
        }
        if ($until = $this->deferUntil($message)) {
            $message->forceFill(['status' => 'pending', 'attempts' => max(0, $message->attempts - 1), 'next_attempt_at' => $until])->save();

            return;
        }

        $account = $message->account;
        if (! $account || ! $this->channels->isReady($account)) {
            $this->fail($message, 'permanent', __('messages.reasons.not_configured', ['channel' => __('messages.channels.'.$message->channel)], 'ro'));

            return;
        }

        try {
            $result = $this->channels->adapter($account->driver)->send($message, $account);
        } catch (SendFailure $failure) {
            $this->fail($message, $failure->kind, $failure->getMessage());

            return;
        }

        $message->forceFill([
            'status' => $result->status,
            'provider_message_id' => $result->providerId,
            'segments' => $result->segments ?? $message->segments,
            'cost_reported_cents' => $result->reportedCostCents,
            'accepted_at' => now(),
            'sent_at' => $result->status === 'sent' ? now() : $message->sent_at,
            'status_reason' => null,
            'next_attempt_at' => null,
        ])->save();
        $this->event($message, 'sender', $result->status);
    }

    /** Why a queued message must not go out any more, or null. */
    private function staleReason(Message $message): ?string
    {
        if ($message->appointment_id) {
            $appointment = $message->appointment;
            if (! $appointment) {
                return 'appointment_deleted';
            }
            $active = in_array($appointment->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true);
            $sameTime = $message->context_starts_at && $appointment->starts_at->equalTo($message->context_starts_at);
            if ($message->type === 'appointment_cancelled') {
                return $appointment->status === AppointmentStatus::Cancelled ? null : 'appointment_not_cancelled';
            }
            if (! $active) {
                return 'appointment_'.$appointment->status->value;
            }
            if (! $sameTime) {
                return 'appointment_moved';
            }
            if ($message->type === 'appointment_reminder' && $appointment->starts_at->isPast()) {
                return 'appointment_passed';
            }
        }
        if ($message->category === 'marketing') {
            $campaign = $message->campaign;
            if (! $campaign || $campaign->status === 'stopped') {
                return 'campaign_stopped';
            }
            // Consent is checked again at sending: a withdrawal after queuing wins (MKT-02).
            $patient = $message->patient;
            if (! $patient || ! $this->consents->allowsMarketing($patient->id, $patient->phone, $patient->email, $message->channel)) {
                return 'no_consent';
            }
            $limit = (int) Settings::get('communications.marketing_per_week', 2);
            $recent = Message::where('patient_id', $patient->id)->where('category', 'marketing')->where('id', '!=', $message->id)
                ->whereIn('status', ['accepted', 'sent', 'delivered'])->where('accepted_at', '>=', now()->subDays(7))->count();
            if ($recent >= $limit) {
                return 'frequency_limit';
            }
        }

        return null;
    }

    /** Later time when the message must wait (paused campaign, quiet hours, provider rate), or null. */
    private function deferUntil(Message $message): ?CarbonImmutable
    {
        if ($message->category === 'marketing' && $message->campaign?->status === 'paused') {
            return CarbonImmutable::now()->addMinutes(10);
        }
        // Confirmations follow the patient's own action and go out at once; the rest respect quiet hours.
        if (in_array($message->type, ['appointment_reminder', 'recall', 'marketing'], true) && ($end = $this->quietUntil())) {
            return $end;
        }
        $account = $message->account;
        if ($account && $account->per_minute_limit > 0) {
            $lastMinute = Message::where('account_id', $account->id)->where('accepted_at', '>=', now()->subMinute())->count();
            if ($lastMinute >= $account->per_minute_limit) {
                return CarbonImmutable::now()->addMinute();
            }
        }

        return null;
    }

    /** End of the current quiet interval in the clinic's time zone, or null outside it (MKT-03). */
    public function quietUntil(?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $quiet = (array) Settings::get('communications.quiet_hours', ['start' => '21:00', 'end' => '08:00']);
        if (empty($quiet['start']) || empty($quiet['end'])) {
            return null;
        }
        $tz = config('optica.clinic_timezone');
        $now = ($now ?? CarbonImmutable::now())->setTimezone($tz);
        $start = $now->setTimeFromTimeString($quiet['start']);
        $end = $now->setTimeFromTimeString($quiet['end']);
        if ($start > $end) {
            // Overnight interval, e.g. 21:00–08:00.
            if ($now >= $start) {
                return $end->addDay()->utc();
            }

            return $now < $end ? $end->utc() : null;
        }

        return $now >= $start && $now < $end ? $end->utc() : null;
    }

    private function fail(Message $message, string $kind, string $reason): void
    {
        if ($kind === 'transient' && $message->attempts < count(self::BACKOFF) + 1) {
            $message->forceFill(['status' => 'pending', 'status_reason' => $reason, 'next_attempt_at' => now()->addMinutes(self::BACKOFF[$message->attempts - 1] ?? 60)])->save();
            $this->event($message, 'sender', 'retry', $reason);

            return;
        }
        if ($kind === 'uncertain') {
            // The provider may have the message: staff check it instead of an automatic duplicate.
            $this->finish($message, 'uncertain', $reason);

            return;
        }
        $this->finish($message, 'failed', $reason);
        $this->fallback($message);
    }

    private function finish(Message $message, string $status, ?string $reason): void
    {
        $message->forceFill([
            'status' => $status,
            'status_reason' => $reason ? mb_substr($reason, 0, 300) : null,
            'failed_at' => $status === 'failed' ? now() : $message->failed_at,
            'next_attempt_at' => null,
        ])->save();
        $this->event($message, 'sender', $status, $reason);
    }

    /**
     * Next channel of the rule, only after a definitive failure and only for operational messages
     * (COM-05). An unread message is never a reason; each channel is tried at most once.
     */
    public function fallback(Message $failed): ?Message
    {
        if ($failed->category !== 'operational') {
            return null;
        }
        $rule = $this->dispatcher->rule($failed->type, $failed->location_id);
        $chain = $rule?->channels ?? [];
        $position = array_search($failed->channel, $chain, true);
        if ($position === false) {
            return null;
        }
        $base = Str::beforeLast((string) $failed->idempotency_key, ':');
        $patient = $failed->patient;
        $appointment = $failed->appointment;
        $phone = $patient?->phone ?? $appointment?->contact_phone;
        $email = $patient?->email ?? $appointment?->contact_email;
        $reasons = [];
        foreach (array_slice($chain, $position + 1) as $channel) {
            if (Message::where('idempotency_key', $base.':'.$channel)->exists()) {
                continue;
            }
            $built = $this->dispatcher->build($failed->type, $channel, $failed->location_id, $failed->locale, (array) $failed->vars, $phone, $email, $reasons);
            if ($built) {
                return Message::create($built + [
                    'uuid' => (string) Str::uuid(),
                    'type' => $failed->type,
                    'category' => 'operational',
                    'patient_id' => $failed->patient_id,
                    'appointment_id' => $failed->appointment_id,
                    'order_id' => $failed->order_id,
                    'location_id' => $failed->location_id,
                    'context_starts_at' => $failed->context_starts_at,
                    'send_after' => now(),
                    'fallback_of_id' => $failed->id,
                    'idempotency_key' => $base.':'.$channel,
                ]);
            }
        }

        return null;
    }

    /** Applies a provider status (webhook), idempotently and never backwards (COM-03). */
    public function applyProviderStatus(string $source, string $eventKey, string $providerId, string $status, ?string $detail = null, ?int $reportedCostCents = null): ?Message
    {
        $message = Message::where('provider_message_id', $providerId)->first();
        try {
            MessageEvent::create(['message_id' => $message?->id, 'source' => $source, 'event_key' => mb_substr($eventKey, 0, 190), 'status' => $status,
                'detail' => $detail ? mb_substr($detail, 0, 300) : null, 'created_at' => now()]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return $message;
        }
        if (! $message || (Message::RANK[$status] ?? 0) <= (Message::RANK[$message->status] ?? 0)) {
            return $message;
        }
        $message->forceFill([
            'status' => $status,
            'sent_at' => $status === 'sent' ? now() : $message->sent_at,
            'delivered_at' => $status === 'delivered' ? now() : $message->delivered_at,
            'failed_at' => $status === 'failed' ? now() : $message->failed_at,
            'status_reason' => $status === 'failed' ? $detail : $message->status_reason,
            'cost_reported_cents' => $reportedCostCents ?? $message->cost_reported_cents,
        ])->save();
        if ($status === 'failed') {
            $this->fallback($message);
        }

        return $message;
    }

    private function event(Message $message, string $source, string $status, ?string $detail = null): void
    {
        MessageEvent::create(['message_id' => $message->id, 'source' => $source, 'event_key' => $source.':'.$message->id.':'.$status.':'.$message->attempts.':'.Str::random(6),
            'status' => $status, 'detail' => $detail ? mb_substr($detail, 0, 300) : null, 'created_at' => now()]);
    }
}
