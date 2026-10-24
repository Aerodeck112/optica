<?php

namespace App\Modules\Finance\Services\Payments;

use App\Modules\Core\Models\Integration;

class PaymentGatewayFactory
{
    public function active(): ?PaymentGateway
    {
        $integration = Integration::query()->where('key', 'payments')->first();
        if (! $integration || ! $integration->isUsable()) {
            return null;
        }

        return $this->make((string) $integration->driver, $integration->decryptedCredentials());
    }

    /** Pentru webhook-uri: furnizorul după nume, chiar dacă plățile noi sunt oprite (confirmările întârziate se procesează). */
    public function byName(string $name): ?PaymentGateway
    {
        $integration = Integration::query()->where('key', 'payments')->where('driver', $name)->first();

        return $integration ? $this->make($name, $integration->decryptedCredentials()) : null;
    }

    public function make(string $driver, array $credentials): ?PaymentGateway
    {
        return match ($driver) {
            'stripe' => new StripeGateway($credentials),
            default => null,
        };
    }
}
