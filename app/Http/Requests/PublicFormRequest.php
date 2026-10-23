<?php

namespace App\Http\Requests;

use App\Modules\Core\Settings\SettingsRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Http;

/**
 * Bază pentru formularele publice: anti-spam accesibil (honeypot, timp minim, Turnstile opțional).
 */
abstract class PublicFormRequest extends FormRequest
{
    protected int $minSeconds = 3;

    public function authorize(): bool
    {
        return true;
    }

    public function isSpam(): bool
    {
        if (filled($this->input('website'))) {
            return true;
        }
        $started = (int) $this->input('_started');
        if ($started > 0 && time() - $started < $this->minSeconds) {
            return true;
        }

        $secret = app(SettingsRepository::class)->filled('site.anti_spam_turnstile_secret');
        if ($secret) {
            try {
                $response = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => $secret,
                    'response' => (string) $this->input('cf-turnstile-response'),
                    'remoteip' => $this->ip(),
                ]);

                return ! ($response->json('success') === true);
            } catch (\Throwable) {
                return false; // dacă serviciul extern nu răspunde, nu blocăm clienții reali
            }
        }

        return false;
    }
}
