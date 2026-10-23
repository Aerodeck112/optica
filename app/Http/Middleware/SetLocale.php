<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next, ?string $locale = null): Response
    {
        $locale = Locales::isSupported($locale) && in_array($locale, Locales::enabled(), true) ? $locale : Locales::DEFAULT;

        app()->setLocale($locale);
        Carbon::setLocale($locale);
        CarbonImmutable::setLocale($locale);
        $request->attributes->set('locale', $locale);

        return $next($request);
    }
}
