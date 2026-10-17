<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public site: Romanian without prefix, Hungarian under /hu (same URLs as the current site, D-011).
 */
class UseSiteLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->segment(1) === 'hu' ? 'hu' : 'ro';
        app()->setLocale($locale);
        $request->attributes->set('site_locale', $locale);

        return $next($request);
    }
}
