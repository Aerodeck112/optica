<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Display\Models\DisplayScreen;
use App\Modules\Display\Services\DisplayStateBuilder;
use App\Modules\Display\Services\DisplayVersion;
use App\Modules\Display\Services\PairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * API-ul playerului de ecran. Activare cu cod de unică folosință, apoi stare read-only cu ETag (304 când nu s-a schimbat nimic)
 * și heartbeat pentru starea online/offline din CRM.
 */
class DisplayApiController extends Controller
{
    public function __construct(
        private readonly DisplayStateBuilder $builder,
        private readonly PairingService $pairing,
    ) {}

    public function activate(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'resolution' => ['nullable', 'string', 'max:20'],
        ]);
        $screen = DisplayScreen::query()->where('slug', $slug)->where('is_active', true)->first();
        $token = $screen ? $this->pairing->activate($screen, $data['code'], [
            'user_agent' => (string) $request->userAgent(),
            'resolution' => $data['resolution'] ?? null,
            'label' => 'PC monitor ('.($data['resolution'] ?? '?').')',
        ]) : null;
        if (! $token) {
            return response()->json(['error' => 'Cod invalid, expirat sau deja folosit. Generează un cod nou din CRM.'], 422)->header('Cache-Control', 'no-store');
        }

        return response()->json(['token' => $token])->header('Cache-Control', 'no-store');
    }

    public function state(Request $request, string $slug): Response|JsonResponse
    {
        /** @var DisplayScreen $screen */
        $screen = $request->attributes->get('display_screen');

        return $this->respond($request, $screen);
    }

    public function heartbeat(Request $request, string $slug): JsonResponse
    {
        /** @var DisplayScreen $screen */
        $screen = $request->attributes->get('display_screen');
        $data = $request->validate([
            'version' => ['nullable', 'string', 'max:20'],
            'resolution' => ['nullable', 'string', 'max:20'],
            'uptime' => ['nullable', 'integer', 'min:0'],
            'heap_mb' => ['nullable', 'numeric', 'min:0'],
            'scene' => ['nullable', 'string', 'max:30'],
            'offline' => ['nullable', 'boolean'],
            'errors' => ['nullable', 'integer', 'min:0'],
        ]);
        DB::table('display_screens')->where('id', $screen->id)->update([
            'last_seen_at' => now(),
            'player_version' => $data['version'] ?? $screen->player_version,
            'resolution' => isset($data['resolution']) && preg_match('/^\d{3,5}x\d{3,5}$/', $data['resolution']) ? $data['resolution'] : $screen->resolution,
            'telemetry' => json_encode(array_intersect_key($data, array_flip(['uptime', 'heap_mb', 'scene', 'offline', 'errors'])) + ['at' => now()->toIso8601ZuluString()]),
        ]);

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }

    /** Răspunsul comun (dispozitiv și previzualizarea din CRM). */
    public function respond(Request $request, DisplayScreen $screen, bool $recordDelivery = true): Response|JsonResponse
    {
        $payload = Cache::remember('display:state:'.$screen->id.':'.DisplayVersion::current().':'.intdiv(time(), 10), 15,
            fn () => $this->builder->build($screen->fresh() ?? $screen));
        $etag = '"'.md5((string) json_encode($payload)).'"';
        $headers = [
            'ETag' => $etag,
            'X-Server-Time' => (string) (int) floor(microtime(true) * 1000),
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Access-Control-Expose-Headers' => 'ETag, X-Server-Time',
        ];
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, $headers);
        }
        if ($recordDelivery) {
            DB::table('display_screens')->where('id', $screen->id)->update(['last_data_at' => now()]);
        }

        return response()->json($payload, 200, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
