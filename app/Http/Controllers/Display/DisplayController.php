<?php

namespace App\Http\Controllers\Display;

use App\Http\Controllers\Controller;
use App\Modules\Display\Models\DisplayScreen;
use Illuminate\Http\Response;

/**
 * Pagina dedicată fiecărui monitor (/display/arena-live, /display/hall-of-legends). Pagina nu conține date:
 * playerul le cere prin API cu credențiala dispozitivului. Fără meniuri, cookie banner sau chat; neindexată.
 */
class DisplayController extends Controller
{
    public const PLAYER_VERSION = '1.0.0';

    public function show(string $slug): Response
    {
        $screen = DisplayScreen::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();

        return response()->view('display.player', ['config' => [
            'mode' => 'device',
            'slug' => $screen->slug,
            'type' => $screen->type,
            'version' => self::PLAYER_VERSION,
            'stateUrl' => url('/api/display/'.$screen->slug.'/state'),
            'heartbeatUrl' => url('/api/display/'.$screen->slug.'/heartbeat'),
            'activateUrl' => url('/api/display/'.$screen->slug.'/activate'),
            'swUrl' => url('/display/sw.js'),
        ]])->withHeaders(self::privateHeaders());
    }

    /** Service worker-ul ecranelor: cache controlat pentru imaginile aprobate (fără datele jucătorilor). */
    public function serviceWorker(): Response
    {
        return response(file_get_contents(resource_path('js/display/sw.js')), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/display/',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /** @return array<string, string> */
    public static function privateHeaders(): array
    {
        return ['X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer'];
    }
}
