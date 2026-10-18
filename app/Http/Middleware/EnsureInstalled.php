<?php

namespace App\Http\Middleware;

use App\Support\Install\InstallState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Before installation only the installer answers; afterwards the installer is gone (INS-02). */
class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        $installer = $request->is('install', 'install/*');
        if (! InstallState::installed() && ! $installer && ! $request->is('up')) {
            return redirect('/install');
        }
        if ($installer && InstallState::installed()) {
            abort(404);
        }

        return $next($request);
    }
}
