<?php

namespace App\Modules\Crm\Services;

use App\Modules\Crm\Models\Consent;
use App\Modules\Crm\Models\ConsentEvent;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Models\Suppression;
use App\Support\ContactNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Consimțământ de marketing separat pe canal (e-mail / SMS). Acceptarea termenilor rezervării nu este consimțământ de marketing.
 * Fiecare acordare/retragere este păstrată în consent_events, cu dovada.
 */
class ConsentService
{
    public function grant(Customer $customer, string $channel, string $source, array $proof = [], string $actorType = 'customer', ?int $actorId = null): Consent
    {
        $this->assertChannel($channel);

        return DB::transaction(function () use ($customer, $channel, $source, $proof, $actorType, $actorId) {
            $consent = Consent::query()->updateOrCreate(
                ['customer_id' => $customer->id, 'channel' => $channel, 'purpose' => 'marketing'],
                ['status' => 'granted', 'source' => $source, 'proof' => $proof, 'granted_at' => now(), 'withdrawn_at' => null],
            );

            ConsentEvent::query()->create([
                'customer_id' => $customer->id,
                'channel' => $channel,
                'purpose' => 'marketing',
                'action' => 'granted',
                'source' => $source,
                'proof' => $proof,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
            ]);

            // O nouă acordare explicită ridică excluderea „unsubscribed” (nu și bounce/complaint).
            $hash = ContactNormalizer::hash($channel, $channel === 'sms' ? $customer->phone : $customer->email);
            if ($hash) {
                Suppression::query()->where('channel', $channel)->where('value_hash', $hash)->where('reason', 'unsubscribed')->delete();
            }

            return $consent;
        });
    }

    public function withdraw(Customer $customer, string $channel, string $source, array $proof = [], string $actorType = 'customer', ?int $actorId = null): void
    {
        $this->assertChannel($channel);

        DB::transaction(function () use ($customer, $channel, $source, $proof, $actorType, $actorId) {
            Consent::query()->updateOrCreate(
                ['customer_id' => $customer->id, 'channel' => $channel, 'purpose' => 'marketing'],
                ['status' => 'withdrawn', 'source' => $source, 'withdrawn_at' => now()],
            );

            ConsentEvent::query()->create([
                'customer_id' => $customer->id,
                'channel' => $channel,
                'purpose' => 'marketing',
                'action' => 'withdrawn',
                'source' => $source,
                'proof' => $proof,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
            ]);

            $this->suppress($channel, $channel === 'sms' ? $customer->phone : $customer->email, 'unsubscribed');
        });
    }

    public function suppress(string $channel, ?string $address, string $reason, string $scope = 'marketing', ?string $note = null): void
    {
        $hash = ContactNormalizer::hash($channel, $address);
        if (! $hash) {
            return;
        }
        Suppression::query()->firstOrCreate(
            ['channel' => $channel, 'value_hash' => $hash, 'scope' => $scope],
            ['reason' => $reason, 'note' => $note],
        );
    }

    /** Verificat chiar înainte de fiecare trimitere de marketing. */
    public function canReceiveMarketing(Customer $customer, string $channel): bool
    {
        if ($customer->isAnonymized() || $customer->merged_into_id) {
            return false;
        }
        $address = $channel === 'sms' ? $customer->phone : $customer->email;
        if (! $address || $this->isSuppressed($channel, $address, 'marketing')) {
            return false;
        }

        return $customer->hasMarketingConsent($channel);
    }

    public function isSuppressed(string $channel, ?string $address, string $category = 'marketing'): bool
    {
        $hash = ContactNormalizer::hash($channel, $address);
        if (! $hash) {
            return true;
        }
        $scopes = $category === 'marketing' ? ['marketing', 'all'] : ['all'];

        return Suppression::query()->where('channel', $channel)->where('value_hash', $hash)->whereIn('scope', $scopes)->exists();
    }

    private function assertChannel(string $channel): void
    {
        if (! in_array($channel, ['email', 'sms'], true)) {
            throw new \InvalidArgumentException("Canal necunoscut: {$channel}");
        }
    }
}
