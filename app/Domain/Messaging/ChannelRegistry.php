<?php

namespace App\Domain\Messaging;

use App\Domain\Messaging\Channels\ChannelAdapter;
use App\Domain\Messaging\Channels\SmtpAdapter;
use App\Domain\Messaging\Channels\TwilioSmsAdapter;
use App\Domain\Messaging\Channels\WhatsAppCloudAdapter;
use App\Models\ChannelAccount;

/**
 * Accounts and their adapters. A channel without an active, complete account is "not configured"
 * and nothing is sent on it (COM-01): no simulated success.
 */
class ChannelRegistry
{
    public function adapter(string $driver): ChannelAdapter
    {
        return match ($driver) {
            'smtp' => app(SmtpAdapter::class),
            'twilio' => app(TwilioSmsAdapter::class),
            'whatsapp_cloud' => app(WhatsAppCloudAdapter::class),
            default => throw new \InvalidArgumentException($driver),
        };
    }

    /** @return list<string> missing credential keys */
    public function missing(ChannelAccount $account): array
    {
        $missing = array_values(array_filter($this->adapter($account->driver)->requiredCredentials(), fn ($key) => $account->credential($key) === null));
        if (! $account->sender && ! ($account->driver === 'twilio' && $account->credential('messaging_service_sid'))) {
            $missing[] = 'sender';
        }

        return $missing;
    }

    public function isReady(ChannelAccount $account): bool
    {
        return $account->is_active && $this->missing($account) === [];
    }

    /** The account used for a channel at a location: a location's own account wins over the general one. */
    public function accountFor(string $channel, ?int $locationId): ?ChannelAccount
    {
        return ChannelAccount::where('channel', $channel)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('location_id')->when($locationId, fn ($q) => $q->orWhere('location_id', $locationId)))
            ->orderByRaw('location_id is null')->orderBy('id')->get()
            ->first(fn ($account) => $this->isReady($account));
    }

    /** @return array<string, 'ready'|'incomplete'|'inactive'|'missing'> */
    public function overview(): array
    {
        $status = [];
        foreach (ChannelAccount::CHANNELS as $channel) {
            $accounts = ChannelAccount::where('channel', $channel)->get();
            $status[$channel] = match (true) {
                $accounts->contains(fn ($a) => $this->isReady($a)) => 'ready',
                $accounts->contains(fn ($a) => $a->is_active) => 'incomplete',
                $accounts->isNotEmpty() => 'inactive',
                default => 'missing',
            };
        }

        return $status;
    }
}
