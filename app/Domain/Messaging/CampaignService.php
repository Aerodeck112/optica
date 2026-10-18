<?php

namespace App\Domain\Messaging;

use App\Models\Campaign;
use App\Models\Consent;
use App\Models\Message;
use App\Models\Patient;
use App\Support\Audit\AuditLogger;
use App\Support\Ui\SiteUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Marketing campaigns (MKT-01, MKT-02): recipients are patients whose latest decision for the
 * channel is a consent, filtered only by language and location. Clinical data is never used.
 */
class CampaignService
{
    public function __construct(private readonly ChannelRegistry $channels, private readonly AuditLogger $audit) {}

    /** @return Collection<int, Patient> */
    public function recipients(Campaign $campaign): Collection
    {
        $segment = $campaign->segment ?? [];
        $channel = $campaign->channel;
        // Latest marketing decision per patient for this channel.
        $latest = Consent::query()->select('patient_id', DB::raw('max(id) as id'))
            ->where('purpose', 'marketing')->where('channel', $channel)->whereNotNull('patient_id')->groupBy('patient_id');
        $granted = Consent::query()->joinSub($latest, 'latest', 'latest.id', '=', 'consents.id')->where('consents.granted', true)->pluck('consents.patient_id');

        return Patient::query()->whereIn('id', $granted)->whereNull('merged_into_id')->whereNull('archived_at')
            ->when(! empty($segment['locales']), fn ($q) => $q->whereIn('locale', $segment['locales']))
            ->when(! empty($segment['location_ids']), fn ($q) => $q->whereIn('primary_location_id', $segment['location_ids']))
            ->get()
            ->filter(fn (Patient $p) => Contacts::forChannel($channel, $p->phone, $p->email) !== null)
            ->values();
    }

    /** Creates the messages of a due campaign; each one is re-checked again at sending. */
    public function start(Campaign $campaign): int
    {
        $account = $this->channels->accountFor($campaign->channel, null);
        $created = 0;
        foreach ($this->recipients($campaign) as $patient) {
            $locale = in_array($patient->locale, config('optica.locales'), true) ? $patient->locale : 'ro';
            $content = $campaign->content[$locale] ?? $campaign->content['ro'] ?? [];
            $uuid = (string) Str::uuid();
            $vars = [
                'patient_name' => $patient->fullName(),
                'booking_link' => SiteUrl::route('booking', [], $locale),
                'unsubscribe_link' => \Illuminate\Support\Facades\URL::signedRoute('unsubscribe.show', ['uuid' => $uuid]),
            ];
            $body = TemplateRenderer::render((string) ($content['body'] ?? ''), $vars);
            if ($campaign->channel === 'sms' && ! empty($campaign->content['ascii_only'])) {
                $body = TemplateRenderer::ascii($body);
            }
            $message = Message::firstOrCreate(['idempotency_key' => 'campaign:'.$campaign->id.':patient:'.$patient->id], [
                'uuid' => $uuid,
                'type' => 'marketing',
                'category' => 'marketing',
                'channel' => $campaign->channel,
                'account_id' => $account?->id,
                'patient_id' => $patient->id,
                'campaign_id' => $campaign->id,
                'locale' => $locale,
                'recipient' => Contacts::forChannel($campaign->channel, $patient->phone, $patient->email),
                'subject' => isset($content['subject']) ? TemplateRenderer::render((string) $content['subject'], $vars) : null,
                'body' => $body,
                'vars' => $vars,
                'segments' => $campaign->channel === 'sms' ? SmsSegments::analyse($body)['segments'] : null,
                'send_after' => now(),
            ]);
            $created += $message->wasRecentlyCreated ? 1 : 0;
        }
        $campaign->forceFill(['status' => 'sending', 'recipients_count' => $campaign->messages()->count()])->save();
        $this->audit->log('campaigns.started', $campaign, ['recipients' => $campaign->recipients_count]);

        return $created;
    }

    public function stop(Campaign $campaign): void
    {
        DB::transaction(function () use ($campaign) {
            $campaign->forceFill(['status' => 'stopped'])->save();
            $campaign->messages()->where('status', 'pending')->update(['status' => 'cancelled', 'status_reason' => 'campaign_stopped', 'updated_at' => now()]);
        });
        $this->audit->log('campaigns.stopped', $campaign);
    }

    /** Marks sending campaigns as done once nothing is left in the queue. */
    public function closeFinished(): void
    {
        Campaign::where('status', 'sending')->get()
            ->each(fn (Campaign $c) => $c->messages()->whereIn('status', ['pending', 'sending'])->exists() ?: $c->forceFill(['status' => 'done'])->save());
    }
}
