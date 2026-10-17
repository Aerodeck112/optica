<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRM/admin: the language comes from the employee's own profile, so two employees can work
 * in different languages at the same time (LNG-02). Nothing is stored in shared state.
 */
class UseStaffLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            app()->setLocale($user->preferredLocale());
        } else {
            // Login and password pages: chosen with ?lang=, remembered in the session.
            $requested = $request->query('lang');
            if (is_string($requested) && in_array($requested, config('optica.locales'), true)) {
                $request->session()->put('guest_locale', $requested);
            }
            app()->setLocale($request->session()->get('guest_locale', config('app.locale')));
        }

        return $next($request);
    }
}
