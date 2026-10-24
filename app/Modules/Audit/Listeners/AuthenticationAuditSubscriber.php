<?php

namespace App\Modules\Audit\Listeners;

use App\Models\User;
use App\Modules\Audit\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;

/**
 * Jurnalizează autentificările personalului (reușite, eșuate, blocări) fără parole și fără IP în clar.
 */
class AuthenticationAuditSubscriber
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function onLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }
        $event->user->forceFill(['last_login_at' => now(), 'last_login_ip_hash' => hash_hmac('sha256', (string) request()->ip(), (string) config('app.key'))])->saveQuietly();
        $this->audit->as('user', $event->user->id)->log('auth.login', $event->user, [], ['remember' => $event->remember]);
    }

    public function onLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->audit->as('user', $event->user->id)->log('auth.logout', $event->user);
        }
    }

    public function onFailed(Failed $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }
        $email = (string) ($event->credentials['email'] ?? '');
        $this->audit->as('system')->log('auth.failed', $event->user instanceof User ? $event->user : null, [], ['email_hash' => hash('sha256', mb_strtolower($email))]);
    }

    public function onLockout(Lockout $event): void
    {
        $this->audit->as('system')->log('auth.lockout', null, [], ['email_hash' => hash('sha256', mb_strtolower((string) $event->request->input('email')))]);
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            $event->user->forceFill(['password_changed_at' => now()])->saveQuietly();
            $this->audit->as('user', $event->user->id)->log('auth.password_reset', $event->user);
        }
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
            Failed::class => 'onFailed',
            Lockout::class => 'onLockout',
            PasswordReset::class => 'onPasswordReset',
        ];
    }
}
