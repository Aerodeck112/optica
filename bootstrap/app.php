<?php

use App\Http\Controllers\Site\NotFoundResponder;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\RemoveTrailingSlash;
use App\Http\Middleware\TranslationMode;
use App\Http\Middleware\UseSiteLocale;
use App\Http\Middleware\UseStaffLocale;
use App\Support\Install\EnvBootstrap;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// First request after unzipping: a production .env with a fresh key (INS-01).
EnvBootstrap::ensure(dirname(__DIR__));

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
        // Before routing, so even unknown URLs lead to the installer until it has run.
        $middleware->prepend(EnsureInstalled::class);
        $middleware->web(prepend: [RemoveTrailingSlash::class], append: [TranslationMode::class]);
        // The maintenance bypass cookie is signed by Laravel and read before decryption; an update
        // started from the CRM sets it in a normal response, so it must stay unencrypted (UPD-04).
        $middleware->encryptCookies(except: ['laravel_maintenance']);
        // Provider callbacks carry a signature instead of a CSRF token.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
        $middleware->alias([
            'staff.locale' => UseStaffLocale::class,
            'site.locale' => UseSiteLocale::class,
            'active' => EnsureActiveUser::class,
            'two-factor' => EnsureTwoFactor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Unknown public URLs: redirect manager, 404 log and a translated page (SEO-02).
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson() || $request->is('crm/*', 'admin/*', 'cms/*', 'livewire*', 'api/*', 'webhooks/*')) {
                return null;
            }
            app()->setLocale($request->segment(1) === 'hu' ? 'hu' : 'ro');

            return app(NotFoundResponder::class)->respond($request);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
