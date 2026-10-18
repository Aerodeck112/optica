<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Canonical public URLs have no trailing slash; the old "/servicii/" style answers with a 301
 * on every server, not only on Apache (SEO-02, D-012).
 */
class RemoveTrailingSlash
{
    public function handle(Request $request, Closure $next): Response
    {
        $uri = $request->getRequestUri();
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        if ($request->isMethod('GET') && $path !== '/' && str_ends_with($path, '/')) {
            $query = parse_url($uri, PHP_URL_QUERY);

            return redirect(rtrim($path, '/').($query ? '?'.$query : ''), 301);
        }

        return $next($request);
    }
}
