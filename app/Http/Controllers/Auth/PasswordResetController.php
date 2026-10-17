<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * Time-limited, single-use reset tokens (SEC-01). The response never reveals whether an email exists.
 */
class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', Str::lower($request->string('email')))->where('is_active', true)->first();
        if ($user) {
            app()->setLocale($user->preferredLocale());
            Password::broker()->sendResetLink(['email' => $user->email]);
        }

        return back()->with('status', __('passwords.sent_generic'));
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ]);

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
                app(AuditLogger::class)->log('auth.password_reset', $user, user: $user);
            },
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('crm.login')->with('status', __($status))
            : back()->withErrors(['email' => __($status)]);
    }
}
