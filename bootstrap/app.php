<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\RemoveTrailingSlash;
use App\Http\Middleware\UseSiteLocale;
use App\Http\Middleware\UseStaffLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Unauthenticated staff go to the CRM login; signed-in users hitting guest pages go to the dashboard.
        $middleware->redirectGuestsTo(fn () => route('crm.login'));
        $middleware->redirectUsersTo(fn () => route('crm.dashboard'));
        $middleware->web(prepend: [RemoveTrailingSlash::class]);
        $middleware->alias([
            'staff.locale' => UseStaffLocale::class,
            'site.locale' => UseSiteLocale::class,
            'active' => EnsureActiveUser::class,
            'two-factor' => EnsureTwoFactor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Unknown public URLs: redirect manager, 404 log and a translated page (SEO-02).
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson() || $request->is('crm/*', 'admin/*', 'livewire*', 'api/*')) {
                return null;
            }
            app()->setLocale($request->segment(1) === 'hu' ? 'hu' : 'ro');

            return app(\App\Http\Controllers\Site\NotFoundResponder::class)->respond($request);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
