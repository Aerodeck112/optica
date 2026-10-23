<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Core\Models\Integration;
use App\Modules\Notifications\Contracts\MessageProvider;
use App\Modules\Notifications\Providers\BrevoEmailProvider;
use App\Modules\Notifications\Providers\SandboxProvider;
use App\Modules\Notifications\Providers\SmtpEmailProvider;
use App\Modules\Notifications\Providers\TwilioSmsProvider;

class ProviderFactory
{
    public const DRIVERS = [
        'email' => ['smtp' => 'SMTP (ex: e-mail cPanel)', 'brevo' => 'Brevo (API)'],
        'sms' => ['twilio' => 'Twilio'],
    ];

    public function for(string $channel): MessageProvider
    {
        $integration = Integration::query()->where('key', $channel)->first();
        if (! $integration || ! $integration->isUsable()) {
            return new SandboxProvider;
        }

        return $this->make($channel, (string) $integration->driver, $integration->decryptedCredentials(), (array) $integration->options);
    }

    public function byName(string $name): ?MessageProvider
    {
        foreach (['email', 'sms'] as $channel) {
            $integration = Integration::query()->where('key', $channel)->where('driver', $name)->first();
            if ($integration) {
                return $this->make($channel, $name, $integration->decryptedCredentials(), (array) $integration->options);
            }
        }

        return null;
    }

    public function make(string $channel, string $driver, array $credentials, array $options): MessageProvider
    {
        return match ("{$channel}:{$driver}") {
            'email:smtp' => new SmtpEmailProvider($credentials, $options),
            'email:brevo' => new BrevoEmailProvider($credentials, $options),
            'sms:twilio' => new TwilioSmsProvider($credentials, $options),
            default => new SandboxProvider,
        };
    }
}
