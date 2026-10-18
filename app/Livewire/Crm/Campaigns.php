<?php

namespace App\Livewire\Crm;

use App\Domain\Messaging\CampaignService;
use App\Domain\Messaging\ChannelRegistry;
use App\Domain\Messaging\Contacts;
use App\Domain\Messaging\MessageSender;
use App\Domain\Messaging\MessageTypes;
use App\Domain\Messaging\SmsSegments;
use App\Domain\Messaging\TemplateRenderer;
use App\Models\Campaign;
use App\Models\ChannelAccount;
use App\Models\Location;
use App\Models\Message;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Marketing campaigns (MKT-01…03): segments by language and location only, test, schedule, pause,
 * resume and stop; results per state. Opens are not tracked.
 */
class Campaigns extends Component
{
    #[Locked]
    public ?int $editing = null;

    public array $form = [];

    public string $testTo = '';

    public ?string $notice = null;

    public ?string $failure = null;

    public function boot(): void
    {
        $this->authorize('campaigns.view');
    }

    public function create(): void
    {
        $this->authorize('campaigns.manage');
        $this->editing = 0;
        $this->form = ['name' => '', 'channel' => 'email', 'locales' => config('optica.locales'), 'location_ids' => [], 'ascii_only' => false, 'scheduled_at' => '',
            'content' => array_fill_keys(config('optica.locales'), ['subject' => '', 'body' => ''])];
    }

    public function edit(int $id): void
    {
        $campaign = Campaign::findOrFail($id);
        $this->editing = $campaign->id;
        $content = [];
        foreach (config('optica.locales') as $l) {
            $content[$l] = ['subject' => (string) ($campaign->content[$l]['subject'] ?? ''), 'body' => (string) ($campaign->content[$l]['body'] ?? '')];
        }
        $this->form = ['name' => $campaign->name, 'channel' => $campaign->channel, 'locales' => $campaign->segment['locales'] ?? [], 'location_ids' => array_map('intval', $campaign->segment['location_ids'] ?? []),
            'ascii_only' => (bool) ($campaign->content['ascii_only'] ?? false), 'scheduled_at' => $campaign->scheduled_at?->timezone(config('optica.clinic_timezone'))->format('Y-m-d\TH:i') ?? '', 'content' => $content];
        $this->notice = $this->failure = null;
    }

    public function cancel(): void
    {
        $this->editing = null;
    }

    private function campaign(): ?Campaign
    {
        return $this->editing ? Campaign::findOrFail($this->editing) : null;
    }

    public function save(): ?Campaign
    {
        $this->authorize('campaigns.manage');
        $campaign = $this->campaign();
        if ($campaign && ! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            $this->failure = __('messages.campaigns.locked');

            return null;
        }
        $channel = $this->form['channel'] ?? '';
        $rules = [
            'form.name' => ['required', 'string', 'max:160'],
            'form.channel' => ['required', Rule::in(ChannelAccount::CHANNELS)],
            'form.locales' => ['required', 'array', 'min:1'],
            'form.locales.*' => [Rule::in(config('optica.locales'))],
            'form.location_ids.*' => ['integer', 'exists:locations,id'],
            'form.scheduled_at' => ['nullable', 'date'],
        ];
        foreach ($this->form['locales'] ?? [] as $locale) {
            $rules["form.content.$locale.body"] = ['required', 'string', 'max:'.($channel === 'sms' ? 800 : 6000)];
            $rules["form.content.$locale.subject"] = [$channel === 'email' ? 'required' : 'nullable', 'nullable', 'string', 'max:200'];
        }
        $this->validate($rules, [], ['form.name' => __('messages.campaigns.name')]);
        foreach ($this->form['locales'] as $locale) {
            $text = $this->form['content'][$locale]['body'].' '.$this->form['content'][$locale]['subject'];
            if ($invalid = TemplateRenderer::invalid('marketing', $text)) {
                $this->addError("form.content.$locale.body", __('messages.templates.invalid_variables', ['list' => implode(', ', $invalid)]));

                return null;
            }
            // Every marketing email offers a one-click way out (MKT-02).
            if ($channel === 'email' && ! str_contains($this->form['content'][$locale]['body'], '{unsubscribe_link}')) {
                $this->addError("form.content.$locale.body", __('messages.campaigns.missing_unsubscribe'));

                return null;
            }
        }

        $campaign ??= new Campaign(['created_by' => auth()->id()]);
        $campaign->fill([
            'name' => $this->form['name'],
            'channel' => $channel,
            'segment' => ['locales' => array_values($this->form['locales']), 'location_ids' => array_values(array_map('intval', $this->form['location_ids'] ?? []))],
            'content' => array_intersect_key($this->form['content'], array_flip($this->form['locales'])) + ['ascii_only' => $channel === 'sms' && $this->form['ascii_only']],
            'scheduled_at' => $this->form['scheduled_at'] ? CarbonImmutable::parse($this->form['scheduled_at'], config('optica.clinic_timezone'))->utc() : null,
        ])->save();
        app(AuditLogger::class)->log('campaigns.saved', $campaign);
        $this->editing = $campaign->id;
        $this->notice = __('messages.campaigns.saved');

        return $campaign;
    }

    public function schedule(ChannelRegistry $registry): void
    {
        $this->authorize('campaigns.send');
        $campaign = $this->save();
        if (! $campaign) {
            return;
        }
        if (! $registry->accountFor($campaign->channel, null)) {
            $this->failure = __('messages.campaigns.channel_not_ready', ['channel' => __('messages.channels.'.$campaign->channel)]);

            return;
        }
        $campaign->forceFill(['status' => 'scheduled', 'scheduled_at' => $campaign->scheduled_at ?? now()])->save();
        app(AuditLogger::class)->log('campaigns.scheduled', $campaign);
        $this->notice = __('messages.campaigns.statuses.scheduled');
    }

    public function setStatus(int $id, string $status, CampaignService $service): void
    {
        $this->authorize('campaigns.send');
        $campaign = Campaign::findOrFail($id);
        match (true) {
            $status === 'paused' && $campaign->status === 'sending' => $campaign->forceFill(['status' => 'paused'])->save(),
            $status === 'sending' && $campaign->status === 'paused' => $campaign->forceFill(['status' => 'sending'])->save(),
            $status === 'draft' && $campaign->status === 'scheduled' => $campaign->forceFill(['status' => 'draft'])->save(),
            $status === 'stopped' && in_array($campaign->status, ['scheduled', 'sending', 'paused'], true) => $service->stop($campaign),
            default => abort(422),
        };
        app(AuditLogger::class)->log('campaigns.'.$status, $campaign);
    }

    /** One real message to the address typed by the user, with fictitious data. */
    public function sendTest(MessageSender $sender, ChannelRegistry $registry): void
    {
        $this->authorize('campaigns.send');
        $channel = $this->form['channel'] ?? 'email';
        $recipient = Contacts::forChannel($channel, $this->testTo, $this->testTo);
        $account = $registry->accountFor($channel, null);
        if (! $recipient || ! $account || $channel === 'whatsapp') {
            $this->failure = ! $account ? __('messages.campaigns.channel_not_ready', ['channel' => __('messages.channels.'.$channel)]) : __('messages.campaigns.test_unavailable');

            return;
        }
        $locale = $this->form['locales'][0] ?? 'ro';
        $vars = MessageTypes::sample($locale);
        $body = TemplateRenderer::render($this->form['content'][$locale]['body'] ?? '', $vars);
        $message = Message::create(['uuid' => (string) Str::uuid(), 'type' => 'test', 'category' => 'test', 'channel' => $channel, 'account_id' => $account->id,
            'locale' => $locale, 'recipient' => $recipient, 'subject' => TemplateRenderer::render($this->form['content'][$locale]['subject'] ?? '', $vars) ?: null,
            'body' => $channel === 'sms' && ($this->form['ascii_only'] ?? false) ? TemplateRenderer::ascii($body) : $body, 'send_after' => now(), 'created_by' => auth()->id()]);
        $sender->process($message->id);
        $message->refresh();
        in_array($message->status, ['accepted', 'sent', 'delivered'], true)
            ? $this->notice = __('messages.accounts.test_sent', ['status' => __('messages.statuses.'.$message->status)])
            : $this->failure = __('messages.accounts.test_failed', ['reason' => $message->status_reason]);
    }

    public function render(CampaignService $service)
    {
        $campaign = $this->editing ? Campaign::find($this->editing) : null;
        $previewCampaign = $this->editing !== null ? new Campaign(['channel' => $this->form['channel'] ?? 'email',
            'segment' => ['locales' => $this->form['locales'] ?? [], 'location_ids' => $this->form['location_ids'] ?? []]]) : null;
        $sms = null;
        if (($this->form['channel'] ?? '') === 'sms') {
            $locale = $this->form['locales'][0] ?? 'ro';
            $text = TemplateRenderer::render($this->form['content'][$locale]['body'] ?? '', MessageTypes::sample($locale));
            $sms = SmsSegments::analyse(($this->form['ascii_only'] ?? false) ? TemplateRenderer::ascii($text) : $text);
        }

        return view('livewire.crm.campaigns', [
            'campaigns' => Campaign::withCount(['messages'])->latest('id')->get(),
            'campaign' => $campaign,
            'recipientCount' => $previewCampaign ? $service->recipients($previewCampaign)->count() : null,
            'stats' => $campaign ? $campaign->messages()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status') : collect(),
            'costs' => $campaign ? $campaign->messages()->selectRaw('sum(cost_estimated_cents) as estimated, sum(cost_reported_cents) as reported')->first() : null,
            'locations' => Location::orderBy('sort_order')->get(),
            'sms' => $sms,
            'editable' => ! $campaign || in_array($campaign->status, ['draft', 'scheduled'], true),
        ])->layout('components.layouts.crm')->title(__('messages.campaigns.title'));
    }
}
