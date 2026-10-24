<?php

namespace App\Http\Middleware;

use App\Modules\Finance\Models\FiscalDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentificarea agentului local al casei de marcat: cheie + semnătură HMAC-SHA256 peste
 * „timestamp\nMETODĂ\ncale\nsha256(corp)”, cu toleranță de 5 minute. Secretul nu circulă niciodată pe rețea.
 */
class AuthenticateFiscalAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $keyId = (string) $request->header('X-AW-Key');
        $timestamp = (int) $request->header('X-AW-Timestamp');
        $signature = (string) $request->header('X-AW-Signature');

        $device = $keyId !== '' ? FiscalDevice::query()->where('agent_key_id', $keyId)->where('is_active', true)->where('driver', 'agent')->first() : null;
        if (! $device || ! $device->agent_secret || abs(time() - $timestamp) > 300) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $expected = hash_hmac('sha256', self::canonical($timestamp, $request->method(), '/'.ltrim($request->path(), '/'), $request->getContent()), $device->agent_secret);
        if (! hash_equals($expected, $signature)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $request->attributes->set('fiscal_device', $device);

        return $next($request);
    }

    public static function canonical(int $timestamp, string $method, string $path, string $body): string
    {
        return $timestamp."\n".strtoupper($method)."\n".$path."\n".hash('sha256', $body);
    }
}
