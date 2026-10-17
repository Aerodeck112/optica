<?php

namespace App\Http\Middleware;

use App\Support\Access\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Privileged roles must enrol in 2FA; enrolled users must pass the challenge in this session.
 */
class EnsureTwoFactor
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || $request->session()->get(TwoFactor::SESSION_PASSED) === true) {
            return $next($request);
        }

        if ($this->twoFactor->isEnabled($user)) {
            return redirect()->route('crm.two-factor.reverify');
        }
        if ($this->twoFactor->isRequiredFor($user)) {
            return redirect()->route('crm.two-factor.setup');
        }

        return $next($request);
    }
}
