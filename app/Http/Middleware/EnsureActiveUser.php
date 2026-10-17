<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A deactivated account loses access immediately, including open sessions (SEC-01).
 */
class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && (! $user->is_active || $user->trashed())) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('crm.login')->withErrors(['email' => __('auth.inactive')]);
        }

        return $next($request);
    }
}
