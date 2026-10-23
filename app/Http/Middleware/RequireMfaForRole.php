<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * MFA obligatoriu doar pentru rolurile din User::MFA_REQUIRED_ROLES (manager, contabil, marketing, super admin).
 * Ceilalți utilizatori îl pot activa opțional din profil.
 */
class RequireMfaForRole
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $user->requiresMfa()) {
            return $next($request);
        }

        if (MultiFactorChallenge::make()->hasEnabledProviders($user)) {
            return $next($request);
        }

        return redirect()->guest(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
    }
}
