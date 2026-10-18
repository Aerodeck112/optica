<?php

namespace App\Http\Controllers\Site;

use App\Models\NotFoundLog;
use App\Models\Redirect;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirect manager first (301/302/410), otherwise a logged, translated 404 page (SEO-02).
 */
class NotFoundResponder
{
    public function respond(Request $request): Response
    {
        $path = Redirect::normalize($request->path());
        $redirect = Redirect::where('from_path', $path)->first();
        if ($redirect) {
            $redirect->increment('hits', 1, ['last_hit_at' => now()]);
            if ((int) $redirect->status_code === 410 || ! $redirect->to_url) {
                return response()->view('site.gone', [], 410);
            }

            return redirect($redirect->to_url, (int) $redirect->status_code);
        }

        if (mb_strlen($path) <= 255) {
            $log = NotFoundLog::firstOrNew(['path' => $path]);
            $log->fill([
                'hits' => (int) $log->hits + 1,
                'last_referer' => mb_substr((string) $request->headers->get('referer'), 0, 255) ?: null,
                'last_seen_at' => now(),
            ])->save();
        }

        return response()->view('site.not-found', [], 404);
    }
}
