<?php

namespace App\Http\Middleware;

use App\Modules\Core\Settings\SettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headere de securitate. Site-ul public primește CSP strict cu nonce pentru scripturi;
 * panoul de administrare (Livewire/Alpine) primește o politică separată, compatibilă.
 */
class SecurityHeaders
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(18));
        app()->instance('csp-nonce', $nonce);
        \Illuminate\Support\Facades\Vite::useCspNonce($nonce);

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(self), usb=(), interest-cohort=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if (config('aw.is_staging')) {
            $headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        if ($this->isAdmin($request)) {
            $headers->set('Content-Security-Policy', "default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'self'; object-src 'none'");
            $headers->set('X-Robots-Tag', 'noindex, nofollow');

            return $response;
        }

        if (! $headers->has('Content-Security-Policy') && str_contains((string) $headers->get('Content-Type'), 'text/html')) {
            $headers->set('Content-Security-Policy', $this->publicPolicy($nonce));
        }

        return $response;
    }

    private function publicPolicy(string $nonce): string
    {
        $script = ["'self'", "'nonce-{$nonce}'"];
        $connect = ["'self'"];
        $img = ["'self'", 'data:', 'blob:'];
        $frame = ["'self'"];
        $form = ["'self'"];

        if ($this->settings->filled('analytics.gtm_id') || $this->settings->filled('analytics.ga4_id') || $this->settings->filled('analytics.ads_id')) {
            $script[] = 'https://www.googletagmanager.com';
            $script[] = 'https://www.google-analytics.com';
            $script[] = 'https://googleads.g.doubleclick.net';
            $connect[] = 'https://*.google-analytics.com';
            $connect[] = 'https://*.analytics.google.com';
            $connect[] = 'https://www.googletagmanager.com';
            $connect[] = 'https://*.g.doubleclick.net';
            $img[] = 'https://*.google-analytics.com';
            $img[] = 'https://www.googletagmanager.com';
            $img[] = 'https://*.g.doubleclick.net';
            $img[] = 'https://www.google.com';
            $img[] = 'https://www.google.ro';
            $frame[] = 'https://www.googletagmanager.com';
        }
        if ($this->settings->filled('analytics.meta_pixel_id')) {
            $script[] = 'https://connect.facebook.net';
            $img[] = 'https://www.facebook.com';
            $connect[] = 'https://www.facebook.com';
        }
        if ($this->settings->filled('site.anti_spam_turnstile_site_key')) {
            $script[] = 'https://challenges.cloudflare.com';
            $frame[] = 'https://challenges.cloudflare.com';
        }
        foreach ((array) config('services.payments.form_action_hosts', []) as $host) {
            $form[] = $host;
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', array_unique($script)),
            "style-src 'self' 'unsafe-inline'",
            'img-src '.implode(' ', array_unique($img)),
            "font-src 'self'",
            'connect-src '.implode(' ', array_unique($connect)),
            "media-src 'self' blob:",
            'frame-src '.implode(' ', array_unique($frame)),
            "frame-ancestors 'self'",
            'form-action '.implode(' ', array_unique($form)),
            "base-uri 'self'",
            "object-src 'none'",
            'upgrade-insecure-requests',
        ]);
    }

    private function isAdmin(Request $request): bool
    {
        return $request->is('admin', 'admin/*', 'livewire/*', 'livewire-*/*', 'filament/*');
    }
}
