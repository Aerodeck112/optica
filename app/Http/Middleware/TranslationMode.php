<?php

namespace App\Http\Middleware;

use App\Support\Translation\TranslationRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Translation mode (TRD-01): for an authorised user who switched it on, full HTML pages carry the
 * list of texts they displayed, so the in-page editor can mark them. Usages per page feed the
 * translation centre's page filter.
 */
class TranslationMode
{
    public const SESSION_KEY = 'translation_mode';

    public function __construct(private readonly TranslationRecorder $recorder) {}

    public static function activeFor(Request $request): bool
    {
        $user = $request->user();

        return $request->hasSession() && $request->session()->get(self::SESSION_KEY) === true && $user && $user->can('translations.edit');
    }

    public function handle(Request $request, Closure $next): Response
    {
        $active = $request->isMethod('GET') && ! $request->is('livewire*') && self::activeFor($request);
        if ($active) {
            $this->recorder->enable();
        }
        $response = $next($request);

        $type = (string) $response->headers->get('Content-Type');
        if (! $active || ! str_contains($type, 'text/html') || ! method_exists($response, 'getContent')) {
            return $response;
        }
        $seen = $this->recorder->seen();
        $content = (string) $response->getContent();
        $position = strripos($content, '</body>');
        if ($position !== false) {
            $script = '<script>window.__trDict = '.json_encode($seen, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE).';window.dispatchEvent(new Event("tr-dict"));</script>';
            $response->setContent(substr($content, 0, $position).$script.substr($content, $position));
        }
        $this->recordUsages(array_keys($seen), (string) ($request->route()?->getName() ?? $request->path()));

        return $response;
    }

    /** At most once per page every 10 minutes, so browsing in translation mode stays light. */
    private function recordUsages(array $keys, string $route): void
    {
        if (! $keys || ! Cache::add('tr-usage:'.md5($route), 1, 600)) {
            return;
        }
        $now = now();
        foreach (array_chunk($keys, 300) as $chunk) {
            DB::table('translation_usages')->upsert(array_map(fn ($k) => ['key' => mb_substr($k, 0, 190), 'route' => mb_substr($route, 0, 120), 'last_seen_at' => $now], $chunk), ['key', 'route'], ['last_seen_at']);
        }
    }
}
