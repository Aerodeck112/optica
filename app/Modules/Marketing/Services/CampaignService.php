<?php

namespace App\Modules\Marketing\Services;

use App\Models\User;
use App\Modules\Audit\AuditLogger;
use App\Modules\Core\Settings\SettingsRepository;
use App\Modules\Crm\Models\Customer;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignRecipient;
use App\Modules\Notifications\Models\MessageLog;
use App\Modules\Notifications\Services\Notifier;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Campanii de marketing: estimare (destinatari, cost SMS), test, programare cu aprobare,
 * trimitere în loturi din cron, cu verificarea consimțământului, a excluderilor și a limitei de frecvență.
 */
class CampaignService
{
    private const BATCH = 200;

    public function __construct(
        private readonly Notifier $notifier,
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** Numărul de segmente SMS (GSM-7: 160/153; cu diacritice / Unicode: 70/67). */
    public static function smsSegments(string $text): int
    {
        $gsm = ! preg_match('/[^\x{0020}-\x{007E}\x{00A3}\x{00A5}\x{00E8}\x{00E9}\x{00F9}\x{00EC}\x{00F2}\x{00C7}\x{00D8}\x{00F8}\x{00C5}\x{00E5}\x{00C6}\x{00E6}\x{00DF}\x{00C9}\x{00C4}\x{00D6}\x{00D1}\x{00DC}\x{00A7}\x{00BF}\x{00E4}\x{00F6}\x{00F1}\x{00FC}\x{00E0}\n\r]/u', $text);
        $length = mb_strlen($text);
        [$single, $multi] = $gsm ? [160, 153] : [70, 67];

        return $length <= $single ? 1 : (int) ceil($length / $multi);
    }

    public function smsText(Campaign $campaign): string
    {
        // fiecare SMS de marketing include modalitatea de dezabonare
        return trim($campaign->body).' Dezabonare: {{unsubscribe_short_url}}';
    }

    /** Textul SMS cu un link de dezabonare de lungime tipică, pentru estimarea segmentelor. */
    public function smsTextSample(Campaign $campaign): string
    {
        return str_replace('{{unsubscribe_short_url}}', url('/u/'.str_repeat('x', 14)), $this->smsText($campaign));
    }

    /** @return array{recipients:int, segments_per_message:int, estimated_cost_minor:?int} */
    public function estimate(Campaign $campaign): array
    {
        $recipients = SegmentQuery::build((array) ($campaign->segment?->rules ?? []), $campaign->channel)->count();
        $segments = $campaign->channel === 'sms' ? self::smsSegments($this->smsTextSample($campaign)) : 0;
        $costPerSegment = $this->settings->int('marketing.sms_cost_per_segment_micro');
        $cost = $campaign->channel === 'sms' && $costPerSegment ? (int) ceil($recipients * $segments * $costPerSegment / 10_000) : null;

        $campaign->forceFill(['estimated_cost_minor' => $cost])->save();
        $campaign->segment?->forceFill(['cached_count' => $recipients, 'counted_at' => now()])->save();

        return ['recipients' => $recipients, 'segments_per_message' => $segments, 'estimated_cost_minor' => $cost];
    }

    public function sendTest(Campaign $campaign, string $address, User $user): void
    {
        $allowed = array_map('strtolower', array_merge([$user->email, (string) $user->phone], config('aw.messaging.test_recipients_email', []), config('aw.messaging.test_recipients_sms', [])));
        if (! in_array(mb_strtolower(trim($address)), $allowed, true)) {
            throw new RuntimeException('Testele se trimit doar către adresa ta sau către adresele din lista de test (AW_TEST_EMAILS / AW_TEST_PHONES).');
        }
        $this->notifier->sendRaw($campaign->channel, $address, '[TEST] '.$campaign->subject, $campaign->channel === 'sms' ? $this->smsTextSample($campaign) : $campaign->body, 'service', null, 'campaign-test:'.$campaign->id.':'.now()->format('YmdHis'), $campaign->id);
        $campaign->forceFill(['test_sent_at' => now()])->save();
    }

    public function schedule(Campaign $campaign, CarbonImmutable $at, User $user): Campaign
    {
        if (! $user->can('marketing.send')) {
            throw new RuntimeException('Nu ai permisiunea de a programa campanii.');
        }
        if (! in_array($campaign->status, ['draft', 'paused'], true)) {
            throw new RuntimeException('Campania nu mai poate fi programată.');
        }
        if (! $campaign->test_sent_at) {
            throw new RuntimeException('Trimite mai întâi un mesaj de test și verifică-l.');
        }
        if ($campaign->channel === 'email' && blank($campaign->subject)) {
            throw new RuntimeException('Campania pe e-mail are nevoie de subiect.');
        }
        $estimate = $this->estimate($campaign);
        if ($campaign->channel === 'sms') {
            if ($estimate['estimated_cost_minor'] === null) {
                throw new RuntimeException('Setează costul estimat per segment SMS (Setări → Marketing) înainte de a programa campanii SMS.');
            }
            if ($campaign->cost_cap_minor === null || $estimate['estimated_cost_minor'] > $campaign->cost_cap_minor) {
                throw new RuntimeException('Costul estimat depășește plafonul campaniei sau plafonul nu este setat.');
            }
        }
        $campaign->forceFill(['status' => 'scheduled', 'scheduled_at' => $at, 'approved_by' => $user->id])->save();
        $this->audit->log('campaign.scheduled', $campaign, ['at' => $at->toIso8601String(), 'recipients' => $estimate['recipients']]);

        return $campaign;
    }

    public function cancel(Campaign $campaign, User $user): void
    {
        $campaign->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
        CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('status', 'pending')->update(['status' => 'skipped', 'skip_reason' => 'campaign_cancelled', 'processed_at' => now()]);
        $this->audit->log('campaign.cancelled', $campaign);
    }

    public function isQuietTime(?CarbonImmutable $now = null): bool
    {
        $local = LocalTime::toLocal($now ?? CarbonImmutable::now())->format('H:i');
        $start = (string) $this->settings->get('marketing.quiet_hours_start', '20:00');
        $end = (string) $this->settings->get('marketing.quiet_hours_end', '09:00');

        return $start > $end ? ($local >= $start || $local < $end) : ($local >= $start && $local < $end);
    }

    /** Rulat din cron: pornește campaniile ajunse la termen și trimite câte un lot. */
    public function dispatchDue(): int
    {
        if ($this->isQuietTime()) {
            return 0;
        }
        $processed = 0;
        $campaigns = Campaign::query()->whereIn('status', ['scheduled', 'sending'])->where('scheduled_at', '<=', now())->get();
        foreach ($campaigns as $campaign) {
            $processed += $this->processBatch($campaign);
        }

        return $processed;
    }

    public function processBatch(Campaign $campaign): int
    {
        // „claim” atomic: o singură rulare de cron poate trece campania din „programată” în „se trimite”
        if ($campaign->status === 'scheduled') {
            $claimed = Campaign::query()->whereKey($campaign->id)->where('status', 'scheduled')->update(['status' => 'sending', 'started_at' => now()]);
            if (! $claimed) {
                return 0;
            }
            $this->materializeRecipients($campaign->refresh());
        }

        $capCount = max(1, $this->settings->int('marketing.frequency_cap_count', 2));
        $capDays = max(1, $this->settings->int('marketing.frequency_cap_days', 7));
        $costPerSegment = $this->settings->int('marketing.sms_cost_per_segment_micro');
        $smsText = $campaign->channel === 'sms' ? $this->smsText($campaign) : null;
        $processed = 0;

        $recipients = CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('status', 'pending')->with('customer')->limit(self::BATCH)->get();
        foreach ($recipients as $recipient) {
            $claimed = CampaignRecipient::query()->whereKey($recipient->id)->where('status', 'pending')->update(['status' => 'processing']);
            if (! $claimed) {
                continue;
            }
            $processed++;
            $customer = $recipient->customer;
            $skip = $this->skipReason($campaign, $customer, $capCount, $capDays, $costPerSegment, $smsText);
            if ($skip) {
                $recipient->forceFill(['status' => 'skipped', 'skip_reason' => $skip, 'processed_at' => now()])->save();
                if ($skip === 'cost_cap') {
                    $campaign->forceFill(['status' => 'paused'])->save();
                    CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('status', 'processing')->update(['status' => 'pending']);
                    break;
                }

                continue;
            }
            $address = $campaign->channel === 'sms' ? $customer->phone : $customer->email;
            $log = $this->notifier->sendRaw($campaign->channel, (string) $address, $campaign->subject, $campaign->channel === 'sms' ? (string) $smsText : $campaign->body, 'marketing', $customer, "campaign:{$campaign->id}:customer:{$customer->id}", $campaign->id);
            $recipient->forceFill([
                'status' => $log && $log->status !== 'suppressed' ? 'sent' : 'skipped',
                'skip_reason' => $log && $log->status !== 'suppressed' ? null : 'suppressed',
                'message_log_id' => $log?->id,
                'processed_at' => now(),
            ])->save();
        }

        if (! CampaignRecipient::query()->where('campaign_id', $campaign->id)->whereIn('status', ['pending', 'processing'])->exists() && $campaign->fresh()->status === 'sending') {
            $campaign->forceFill(['status' => 'sent', 'finished_at' => now()])->save();
        }
        $this->refreshStats($campaign);

        return $processed;
    }

    private function materializeRecipients(Campaign $campaign): void
    {
        SegmentQuery::build((array) ($campaign->segment?->rules ?? []), $campaign->channel)
            ->select('id')
            ->chunkById(1000, function ($customers) use ($campaign) {
                $rows = $customers->map(fn ($c) => ['campaign_id' => $campaign->id, 'customer_id' => $c->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()])->all();
                DB::table('campaign_recipients')->insertOrIgnore($rows);
            });
    }

    private function skipReason(Campaign $campaign, ?Customer $customer, int $capCount, int $capDays, ?int $costPerSegment, ?string $smsText): ?string
    {
        if (! $customer || $customer->anonymized_at || $customer->merged_into_id) {
            return 'not_eligible';
        }
        $recent = MessageLog::query()->where('customer_id', $customer->id)->where('category', 'marketing')
            ->whereNotIn('status', ['suppressed', 'failed'])->where('created_at', '>=', now()->subDays($capDays))->count();
        if ($recent >= $capCount) {
            return 'frequency_cap';
        }
        if ($campaign->channel === 'sms' && $campaign->cost_cap_minor !== null && $costPerSegment) {
            $sent = CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('status', 'sent')->count();
            $nextCost = (int) ceil(($sent + 1) * self::smsSegments(str_replace('{{unsubscribe_short_url}}', url('/u/'.str_repeat('x', 14)), (string) $smsText)) * $costPerSegment / 10_000);
            if ($nextCost > $campaign->cost_cap_minor) {
                return 'cost_cap';
            }
        }

        return null;
    }

    public function refreshStats(Campaign $campaign): void
    {
        $byStatus = MessageLog::query()->where('campaign_id', $campaign->id)->where('category', 'marketing')->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();
        $skipped = CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('status', 'skipped')->selectRaw('skip_reason, count(*) as c')->groupBy('skip_reason')->pluck('c', 'skip_reason')->all();
        $campaign->forceFill(['stats' => [
            'recipients' => CampaignRecipient::query()->where('campaign_id', $campaign->id)->count(),
            'messages' => $byStatus,
            'skipped' => $skipped,
            'opened' => MessageLog::query()->where('campaign_id', $campaign->id)->whereNotNull('opened_at')->count(),
            'clicked' => MessageLog::query()->where('campaign_id', $campaign->id)->whereNotNull('clicked_at')->count(),
            'unsubscribed' => DB::table('consent_events')->where('action', 'withdrawn')->where('source', 'unsubscribe_link')->where('created_at', '>=', $campaign->started_at ?? now())->whereIn('customer_id', CampaignRecipient::query()->where('campaign_id', $campaign->id)->select('customer_id'))->count(),
        ]])->save();
    }
}
