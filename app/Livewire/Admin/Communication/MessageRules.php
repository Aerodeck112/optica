<?php

namespace App\Livewire\Admin\Communication;

use App\Domain\Messaging\ChannelRegistry;
use App\Domain\Messaging\MessageTypes;
use App\Models\ChannelAccount;
use App\Models\Location;
use App\Models\MessageRule;
use App\Support\Audit\AuditLogger;
use App\Support\Settings;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Which operational messages are sent and on which channels, generally or for one location
 * (COM-02, COM-05), plus quiet hours and the marketing frequency limit (MKT-03).
 */
class MessageRules extends Component
{
    #[Url]
    public ?int $location = null;

    public array $rules = [];

    public string $quietStart = '';

    public string $quietEnd = '';

    public int $marketingLimit = 2;

    public ?string $notice = null;

    public function boot(): void
    {
        $this->authorize('communications.templates');
    }

    public function mount(): void
    {
        $this->load();
        $quiet = (array) Settings::get('communications.quiet_hours', ['start' => '21:00', 'end' => '08:00']);
        $this->quietStart = (string) ($quiet['start'] ?? '');
        $this->quietEnd = (string) ($quiet['end'] ?? '');
        $this->marketingLimit = (int) Settings::get('communications.marketing_per_week', 2);
    }

    public function updatedLocation(): void
    {
        $this->load();
    }

    private function load(): void
    {
        $stored = MessageRule::where('location_id', $this->location)->get()->keyBy('type');
        $this->rules = [];
        foreach (MessageTypes::operational() as $type) {
            $rule = $stored[$type] ?? null;
            $channels = array_values($rule?->channels ?? []);
            $this->rules[$type] = [
                'exists' => (bool) $rule,
                'enabled' => (bool) $rule?->enabled,
                'c1' => $channels[0] ?? '', 'c2' => $channels[1] ?? '', 'c3' => $channels[2] ?? '',
                'offset' => $rule?->offset_hours ?? ($type === 'appointment_reminder' ? 24 : null),
            ];
        }
    }

    public function save(): void
    {
        $this->validate([
            'rules.*.enabled' => ['boolean'],
            'rules.*.c1' => ['nullable', 'in:'.implode(',', ChannelAccount::CHANNELS)],
            'rules.*.c2' => ['nullable', 'in:'.implode(',', ChannelAccount::CHANNELS)],
            'rules.*.c3' => ['nullable', 'in:'.implode(',', ChannelAccount::CHANNELS)],
            'rules.appointment_reminder.offset' => ['required', 'integer', 'min:1', 'max:168'],
            'quietStart' => ['nullable', 'date_format:H:i'],
            'quietEnd' => ['nullable', 'date_format:H:i', 'required_with:quietStart'],
            'marketingLimit' => ['required', 'integer', 'min:0', 'max:14'],
        ]);
        foreach ($this->rules as $type => $row) {
            $channels = array_values(array_unique(array_filter([$row['c1'], $row['c2'], $row['c3']])));
            // A location keeps the general rule until something is set for it.
            if ($this->location && ! $row['exists'] && ! $row['enabled'] && ! $channels) {
                continue;
            }
            MessageRule::updateOrCreate(['type' => $type, 'location_id' => $this->location], [
                'enabled' => $row['enabled'] && $channels,
                'channels' => $channels,
                'offset_hours' => $type === 'appointment_reminder' ? (int) $row['offset'] : null,
            ]);
        }
        if (! $this->location) {
            Settings::put('communications.quiet_hours', ['start' => $this->quietStart, 'end' => $this->quietEnd], auth()->id());
            Settings::put('communications.marketing_per_week', $this->marketingLimit, auth()->id());
        }
        app(AuditLogger::class)->log('communications.rules_saved', null, ['location_id' => $this->location]);
        $this->load();
        $this->notice = __('messages.rules.saved');
    }

    public function removeOverride(string $type): void
    {
        abort_unless($this->location, 422);
        MessageRule::where('type', $type)->where('location_id', $this->location)->delete();
        $this->load();
    }

    public function render(ChannelRegistry $registry)
    {
        return view('livewire.admin.communication.message-rules', [
            'locations' => Location::orderBy('sort_order')->get(),
            'overview' => $registry->overview(),
        ])->layout('components.layouts.crm')->title(__('messages.rules.title'));
    }
}
