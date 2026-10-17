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
        }

        return $next($request);
    }
}
