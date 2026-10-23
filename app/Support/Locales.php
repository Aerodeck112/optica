<?php

namespace App\Support;

final class Locales
{
    public const DEFAULT = 'ro';

    /** Limbile suportate de arhitectură. O limbă apare public doar când are conținut publicat. */
    public const SUPPORTED = [
        'ro' => ['name' => 'Română', 'hreflang' => 'ro-RO', 'og' => 'ro_RO'],
        'en' => ['name' => 'English', 'hreflang' => 'en', 'og' => 'en_GB'],
        'hu' => ['name' => 'Magyar', 'hreflang' => 'hu', 'og' => 'hu_HU'],
    ];

    public static function codes(): array
    {
        return array_keys(self::SUPPORTED);
    }

    /** Limbile activate pentru site-ul public (setare). Româna este mereu activă. */
    public static function enabled(): array
    {
        $enabled = (array) config('aw.public_locales', [self::DEFAULT]);

        return array_values(array_unique(array_merge([self::DEFAULT], array_intersect($enabled, self::codes()))));
    }

    public static function isSupported(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::SUPPORTED);
    }

    /** Prefixul URL: româna nu are prefix. */
    public static function prefix(string $locale): string
    {
        return $locale === self::DEFAULT ? '' : '/'.$locale;
    }
}
