<?php

namespace App\Modules\Display\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Contor de versiune al datelor afișate: orice schimbare relevantă (sesiune, jucător, playlist, material) îl crește,
 * iar starea ecranelor se recalculează la următoarea interogare.
 */
final class DisplayVersion
{
    private const KEY = 'display:data-version';

    public static function current(): int
    {
        return (int) Cache::get(self::KEY, 1);
    }

    public static function bump(): void
    {
        try {
            if (! Cache::has(self::KEY)) {
                Cache::forever(self::KEY, 1);
            }
            Cache::increment(self::KEY);
        } catch (\Throwable) {
            // cache indisponibil: starea se recalculează oricum la expirarea cache-ului scurt
        }
    }
}
