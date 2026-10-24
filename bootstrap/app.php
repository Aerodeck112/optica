<?php

use App\Http\Middleware\ApplyRedirects;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'locale' => SetLocale::class,
        ]);
        $middleware->append(SecurityHeaders::class);
        $middleware->web(prepend: [ApplyRedirects::class]);

        // Webhook-urile furnizorilor și agentul fiscal au propria autentificare (semnătură/HMAC).
        $middleware->validateCsrfTokens(except: ['webhooks/*', 'api/fiscal-agent/*', 'unsubscribe/*']);

        // Hosting partajat în spatele unui proxy (cPanel cu Cloudflare/LiteSpeed): IP-ul real vine din X-Forwarded-For.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));

        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('admin*') ? '/admin/login' : lroute('account.show'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Nu înregistrăm în log datele introduse în formulare.
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'token', 'card_number']);
    })->create();

/*
| Găzduire cPanel unde domeniul nu poate fi îndreptat spre folderul public/: conținutul lui public/ se află în
| ~/public_html, marcat cu fișierul .aw-public. Aplicația (web și cron) folosește atunci public_html ca folder public.
*/
$publicHtml = dirname(__DIR__, 2).'/public_html';
if (is_file($publicHtml.'/.aw-public')) {
    $app->usePublicPath($publicHtml);
}

return $app;
