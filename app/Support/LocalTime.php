<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Conversii între UTC (stocare) și ora locației (afișare, program, sloturi).
 * Schimbarea orei (EET/EEST) este tratată de baza de date tz a PHP.
 */
final class LocalTime
{
    public static function zone(): string
    {
        return config('aw.timezone', 'Europe/Bucharest');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::zone());
    }

    /** Transformă o dată + oră de pe ceasul local într-un moment UTC. Întoarce null pentru ore inexistente (ora sărită primăvara). */
    public static function fromLocal(string $date, string $time): ?CarbonImmutable
    {
        $local = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date.' '.self::normalizeTime($time), self::zone());

        if ($local === null || $local->format('H:i') !== substr(self::normalizeTime($time), 0, 5)) {
            return null;
        }

        return $local->utc();
    }

    public static function toLocal(?CarbonInterface $moment): ?CarbonImmutable
    {
        return $moment ? CarbonImmutable::instance($moment)->setTimezone(self::zone()) : null;
    }

    public static function format(?CarbonInterface $moment, string $format = 'd.m.Y, H:i'): string
    {
        return $moment ? self::toLocal($moment)->locale(app()->getLocale())->translatedFormat($format) : '';
    }

    /** Data locală (Y-m-d) a unui moment UTC. */
    public static function localDate(CarbonInterface $moment): string
    {
        return self::toLocal($moment)->toDateString();
    }

    private static function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
