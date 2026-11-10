<?php

namespace App\Http\Middleware;

use App\Modules\Display\Services\PairingService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentificarea playerului unui ecran: credențiala dispozitivului (Bearer), verificată ca hash și legată de un singur ecran.
 * Acces read-only la starea ecranului; nicio sesiune de administrator. Fiecare cerere validă actualizează „ultima conexiune”.
 */
class AuthenticateDisplay
{
    public function __construct(private readonly PairingService $pairing) {}

    public function handle(Request $request, Closure $next): Response
    {
        $screen = $this->pairing->authenticate((string) $request->route('slug'), $request->bearerToken());
        if (! $screen) {
            return response()->json(['error' => 'unauthorized'], 401)->header('Cache-Control', 'no-store');
        }

        if (! $screen->last_seen_at || $screen->last_seen_at->lt(now()->subSeconds(15))) {
            // actualizare directă, fără evenimente de model (nu invalidează cache-ul stării)
            DB::table('display_screens')->where('id', $screen->id)->update(array_filter([
                'last_seen_at' => now(),
                'player_version' => mb_substr((string) $request->header('X-Display-Version'), 0, 20) ?: null,
                'resolution' => preg_match('/^\d{3,5}x\d{3,5}$/', (string) $request->header('X-Display-Resolution')) ? $request->header('X-Display-Resolution') : null,
            ]));
        }
        $request->attributes->set('display_screen', $screen);

        return $next($request);
    }
}
