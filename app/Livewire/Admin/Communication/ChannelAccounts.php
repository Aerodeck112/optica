<?php

namespace App\Livewire\Admin\Communication;

use App\Domain\Messaging\ChannelRegistry;
use App\Domain\Messaging\Contacts;
use App\Domain\Messaging\MessageSender;
use App\Models\ChannelAccount;
use App\Models\Location;
use App\Models\Message;
use App\Support\Audit\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Provider accounts (COM-01). Secrets are never sent back to the browser: an empty field keeps the
 * saved value. A test sends one real message, only to the recipient the administrator types.
 */
class ChannelAccounts extends Component
{
    #[Locked]
    public ?int $editing = null;

    public array $form = [];

    public array $secrets = [];

    public string $testTo = '';

    #[Locked]
    public ?int $testing = null;

    public ?string $notice = null;

    public ?string $failure = null;

    public function boot(): void
    {
        $this->authorize('integrations.manage');
    }

    public function create(string $channel): void
    {
        abort_unless(in_array($channel, ChannelAccount::CHANNELS, true), 422);
        $this->editing = 0;
        $this->form = ['channel' => $channel, 'driver' => ChannelAccount::DRIVERS[$channel][0], 'name' => __('messages.channels.'.$channel), 'sender' => '',
            'location_id' => null, 'is_active' => false, 'per_minute_limit' => 30, 'unit_cost' => ''];
        $this->secrets = [];
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $account = ChannelAccount::findOrFail($id);
        $this->editing = $account->id;
        $this->form = [
            'channel' => $account->channel, 'driver' => $account->driver, 'name' => $account->name, 'sender' => (string) $account->sender,
            'location_id' => $account->location_id, 'is_active' => $account->is_active, 'per_minute_limit' => $account->per_minute_limit,
            'unit_cost' => $account->estimated_unit_cost_cents !== null ? Money::input($account->estimated_unit_cost_cents) : '',
        ];
        $this->secrets = [];
        $this->resetErrorBag();
    }

    /** Credential keys shown for a driver: the required ones plus the optional extras. */
    public static function fields(string $driver): array
    {
        return match ($driver) {
            'smtp' => ['host', 'port', 'encryption', 'username', 'password', 'from_name'],
            'twilio' => ['account_sid', 'auth_token', 'messaging_service_sid'],
            'whatsapp_cloud' => ['phone_number_id', 'access_token', 'app_secret', 'verify_token', 'api_version'],
            default => [],
        };
    }

    public function save(ChannelRegistry $registry): void
    {
        $channel = $this->form['channel'] ?? '';
        $this->validate([
            'form.channel' => ['required', Rule::in(ChannelAccount::CHANNELS)],
            'form.driver' => ['required', Rule::in(ChannelAccount::DRIVERS[$channel] ?? [])],
            'form.name' => ['required', 'string', 'max:120'],
            'form.sender' => ['nullable', 'string', 'max:190', $channel === 'email' ? 'email' : 'regex:/^[A-Za-z0-9 +]{2,20}$/'],
            'form.location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'form.is_active' => ['boolean'],
            'form.per_minute_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'form.unit_cost' => ['nullable', fn ($a, $v, $fail) => $v === '' || Money::isValid($v) ? null : $fail(__('finance.errors.amount_invalid'))],
            'secrets.*' => ['nullable', 'string', 'max:500'],
            'secrets.port' => ['nullable', 'integer', 'between:1,65535'],
            'secrets.encryption' => ['nullable', Rule::in(['tls', 'ssl'])],
        ], [], ['form.sender' => __('messages.accounts.sender')]);

        $account = $this->editing ? ChannelAccount::findOrFail($this->editing) : new ChannelAccount;
        $credentials = $account->credentials ?? [];
        foreach (self::fields($this->form['driver']) as $key) {
            if (($this->secrets[$key] ?? '') !== '') {
                $credentials[$key] = trim($this->secrets[$key]);
            }
        }
        $account->fill([
            'channel' => $channel,
            'driver' => $this->form['driver'],
            'name' => $this->form['name'],
            'sender' => $this->form['sender'] ?: null,
            'location_id' => $this->form['location_id'] ?: null,
            'is_active' => (bool) $this->form['is_active'],
            'per_minute_limit' => (int) $this->form['per_minute_limit'],
            'estimated_unit_cost_cents' => ($this->form['unit_cost'] ?? '') !== '' ? Money::parse($this->form['unit_cost']) : null,
            'credentials' => $credentials,
        ])->save();
        // Which keys changed is journaled, never their values.
        app(AuditLogger::class)->log('integrations.account_saved', $account, ['changed_credentials' => array_keys(array_filter($this->secrets))]);
        $this->secrets = [];
        $this->editing = null;
        $this->notice = __('messages.accounts.saved');
    }

    public function cancel(): void
    {
        $this->editing = $this->testing = null;
        $this->secrets = [];
    }

    public function delete(int $id): void
    {
        $account = ChannelAccount::findOrFail($id);
        $account->delete();
        app(AuditLogger::class)->log('integrations.account_deleted', $account, ['channel' => $account->channel]);
        $this->editing = null;
    }

    public function startTest(int $id): void
    {
        $this->testing = ChannelAccount::findOrFail($id)->id;
        $this->testTo = '';
        $this->failure = $this->notice = null;
    }

    /** One real message, sent now, to the address typed by the administrator. */
    public function sendTest(MessageSender $sender, ChannelRegistry $registry): void
    {
        $account = ChannelAccount::findOrFail($this->testing);
        $recipient = Contacts::forChannel($account->channel, $this->testTo, $this->testTo);
        if (! $recipient && $account->channel !== 'whatsapp') {
            $this->addError('testTo', __('validation.regex', ['attribute' => __('messages.accounts.test_to')]));

            return;
        }
        if (! $registry->isReady($account)) {
            $this->failure = __('messages.accounts.missing', ['fields' => implode(', ', array_map(fn ($k) => __('messages.accounts.fields.'.$k) ?: $k, $registry->missing($account)))]);

            return;
        }
        if ($account->channel === 'whatsapp') {
            // Business-initiated WhatsApp needs an approved template, so the test checks the connection
            // to the phone number instead of messaging anyone.
            $result = app(\App\Domain\Messaging\Channels\WhatsAppCloudAdapter::class)->check($account);
            $account->forceFill(['last_tested_at' => now(), 'last_test_result' => mb_substr($result['message'], 0, 300)])->save();
            $result['ok'] ? $this->notice = $result['message'] : $this->failure = $result['message'];
            $this->testing = null;

            return;
        }
        $message = Message::create([
            'uuid' => (string) Str::uuid(), 'type' => 'test', 'category' => 'test', 'channel' => $account->channel, 'account_id' => $account->id,
            'locale' => app()->getLocale(), 'recipient' => $recipient, 'subject' => __('messages.accounts.test'), 'body' => __('messages.accounts.test_body'),
            'send_after' => now(), 'created_by' => auth()->id(),
        ]);
        $sender->process($message->id);
        $message->refresh();
        $result = in_array($message->status, ['accepted', 'sent', 'delivered'], true)
            ? __('messages.accounts.test_sent', ['status' => __('messages.statuses.'.$message->status)])
            : __('messages.accounts.test_failed', ['reason' => $message->status_reason ?? __('messages.statuses.'.$message->status)]);
        $account->forceFill(['last_tested_at' => now(), 'last_test_result' => mb_substr($result, 0, 300)])->save();
        app(AuditLogger::class)->log('integrations.account_tested', $account, ['status' => $message->status]);
        in_array($message->status, ['accepted', 'sent', 'delivered'], true) ? $this->notice = $result : $this->failure = $result;
        $this->testing = null;
    }

    public function render(ChannelRegistry $registry)
    {
        $accounts = ChannelAccount::with('location')->orderBy('channel')->orderBy('id')->get();

        return view('livewire.admin.communication.channel-accounts', [
            'overview' => $registry->overview(),
            'accounts' => $accounts,
            'missing' => $accounts->mapWithKeys(fn ($a) => [$a->id => $registry->missing($a)]),
            'locations' => Location::orderBy('sort_order')->get(),
            'saved' => $this->editing ? array_keys(array_filter(ChannelAccount::find($this->editing)?->credentials ?? [])) : [],
        ])->layout('components.layouts.crm')->title(__('messages.accounts.title'));
    }
}
