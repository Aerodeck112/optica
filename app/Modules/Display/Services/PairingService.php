<?php

namespace App\Modules\Display\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Display\Models\DisplayPairing;
use App\Modules\Display\Models\DisplayScreen;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Asocierea unui PC cu un ecran:
 *  1. din CRM se generează un cod de activare (unică folosință, 30 de minute; se păstrează doar hash-ul);
 *  2. pe PC se deschide adresa ecranului și se introduce codul;
 *  3. serverul emite o credențială proprie dispozitivului (64 de caractere, păstrată doar ca hash), cu acces read-only
 *     la datele acestui ecran. O nouă asociere sau revocarea invalidează credențiala anterioară.
 * PC-ul nu primește niciodată o sesiune de administrator, iar adresa nu conține secrete.
 */
class PairingService
{
    private const ALPHABET = 'ACDEFGHJKMNPQRTUVWXY34679';

    public const CODE_MINUTES = 30;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return string codul în clar, afișat o singură dată în CRM (ex. „K7MQ-4XPA”) */
    public function issueCode(DisplayScreen $screen, ?int $userId = null): string
    {
        $plain = '';
        for ($i = 0; $i < 8; $i++) {
            $plain .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        DB::transaction(function () use ($screen, $plain, $userId) {
            DisplayPairing::query()->where('display_screen_id', $screen->id)->whereNull('used_at')->delete();
            DisplayPairing::query()->create([
                'display_screen_id' => $screen->id,
                'code_hash' => self::hash($plain),
                'expires_at' => now()->addMinutes(self::CODE_MINUTES),
                'created_by' => $userId,
            ]);
        });
        $this->audit->log('display.pairing_code_issued', $screen);

        return substr($plain, 0, 4).'-'.substr($plain, 4);
    }

    /**
     * @return string|null credențiala dispozitivului (în clar, o singură dată) sau null dacă activarea nu este permisă
     */
    public function activate(DisplayScreen $screen, string $code, array $meta = []): ?string
    {
        $limiter = 'display-activate:'.$screen->id;
        if (RateLimiter::tooManyAttempts($limiter, 8)) {
            return null;
        }
        $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
        $pairing = DisplayPairing::query()->where('display_screen_id', $screen->id)->where('code_hash', self::hash($normalized))
            ->whereNull('used_at')->where('expires_at', '>', now())->first();
        if (! $pairing || ! $screen->is_active) {
            RateLimiter::hit($limiter, 900);

            return null;
        }

        $token = Str::random(64);
        DB::transaction(function () use ($pairing, $screen, $token, $meta) {
            $pairing->forceFill(['used_at' => now()])->save();
            $screen->forceFill([
                'device_token_hash' => hash('sha256', $token),
                'device_label' => mb_substr((string) ($meta['label'] ?? 'PC monitor'), 0, 120),
                'user_agent' => mb_substr((string) ($meta['user_agent'] ?? ''), 0, 190),
                'resolution' => mb_substr((string) ($meta['resolution'] ?? ''), 0, 20) ?: null,
                'paired_at' => now(),
                'revoked_at' => null,
                'last_seen_at' => now(),
            ])->save();
        });
        RateLimiter::clear($limiter);
        $this->audit->as('system', null)->log('display.paired', $screen, ['user_agent' => $meta['user_agent'] ?? null]);

        return $token;
    }

    public function authenticate(string $slug, ?string $token): ?DisplayScreen
    {
        if (! $token || strlen($token) < 40) {
            return null;
        }

        return DisplayScreen::query()->where('slug', $slug)->where('is_active', true)->whereNull('revoked_at')
            ->where('device_token_hash', hash('sha256', $token))->first();
    }

    public function revoke(DisplayScreen $screen, ?int $userId = null): void
    {
        $screen->forceFill(['device_token_hash' => null, 'revoked_at' => now()])->save();
        DisplayPairing::query()->where('display_screen_id', $screen->id)->whereNull('used_at')->delete();
        $this->audit->log('display.revoked', $screen);
    }

    private static function hash(string $plain): string
    {
        return hash_hmac('sha256', strtoupper($plain), (string) config('app.key'));
    }
}
