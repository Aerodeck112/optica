<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Access\TwoFactor;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'login:'.Str::lower($credentials['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, config('optica.security.login_max_attempts'))) {
            $this->audit->log('auth.login_locked', meta: ['email' => Str::lower($credentials['email'])]);
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $user = User::where('email', Str::lower($credentials['email']))->first();
        // Same message and timing whether the account exists or not.
        $valid = $user !== null
            ? Hash::check($credentials['password'], $user->password)
            : Hash::check($credentials['password'], '$2y$12$'.str_repeat('a', 53)) && false;

        if (! $valid || ! $user->is_active) {
            RateLimiter::hit($key, config('optica.security.login_decay_seconds'));
            $this->audit->log('auth.login_failed', meta: ['email' => Str::lower($credentials['email'])], user: $user);
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        if ($this->twoFactor->isEnabled($user)) {
            // Password is correct, but the session is only authenticated after the TOTP code.
            $request->session()->put(TwoFactor::SESSION_PENDING_USER, $user->id);
            $request->session()->put('auth.remember', $request->boolean('remember'));

            return redirect()->route('crm.two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        $this->completeLogin($request, $user);

        return redirect()->intended(route('crm.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->audit->log('auth.logout');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('crm.login');
    }

    public function completeLogin(Request $request, User $user): void
    {
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->log('auth.login', user: $user);
    }
}
