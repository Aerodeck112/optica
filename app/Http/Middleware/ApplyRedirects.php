<?php

namespace App\Http\Middleware;

use App\Modules\Cms\Models\Redirect;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirecturi 301/302 și pagini retrase (410) administrate din CMS. Se aplică doar cererilor GET publice.
 */
class ApplyRedirects
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || $request->is('admin*', 'livewire*', 'api/*', 'build/*', 'media/*')) {
            return $next($request);
        }

        $path = '/'.ltrim($request->path(), '/');
        $map = Cache::remember('aw.redirects.v1', 300, fn () => Redirect::query()
            ->where('is_active', true)
            ->get(['id', 'from_path', 'to_url', 'status_code'])
            ->keyBy(fn ($r) => rtrim($r->from_path, '/') ?: '/')
            ->map(fn ($r) => ['id' => $r->id, 'to' => $r->to_url, 'code' => (int) $r->status_code])
            ->all());

        $match = $map[rtrim($path, '/') ?: '/'] ?? null;
        if (! $match) {
            return $next($request);
        }

        Redirect::query()->whereKey($match['id'])->update(['hits' => \DB::raw('hits + 1'), 'last_hit_at' => now()]);

        if ($match['code'] === 410) {
            abort(410);
        }

        return redirect($match['to'] ?: '/', in_array($match['code'], [301, 302, 307, 308], true) ? $match['code'] : 301);
    }
}
