<?php

namespace App\Support\Access;

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor authentication (SEC-01). Secrets are stored encrypted on the user.
 */
class TwoFactor
{
    public const SESSION_PASSED = 'auth.two_factor_passed';

    public const SESSION_PENDING_USER = 'auth.two_factor_user';

    public function __construct(private readonly Google2FA $google2fa) {}

    public function isRequiredFor(User $user): bool
    {
        if (! config('optica.security.two_factor_enforced')) {
            return false;
        }

        return $user->roles()->whereIn('key', config('optica.security.two_factor_roles'))->exists();
    }

    public function isEnabled(User $user): bool
    {
        return $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null;
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);

        return strlen($code) === 6 && ctype_digit($code) && $this->google2fa->verifyKey($secret, $code, 1);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl('Optica Optofarm', $user->email, $secret);
    }

    public function currentCode(string $secret): string
    {
        return $this->google2fa->getCurrentOtp($secret);
    }
}
