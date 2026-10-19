<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A test installation is never indexed by search engines, whatever page is requested. */
class MarkTestSite
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (config('optica.test_site')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
