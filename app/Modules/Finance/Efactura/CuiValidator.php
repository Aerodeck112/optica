<?php

namespace App\Modules\Finance\Efactura;

/** Verificarea cifrei de control a codului unic de înregistrare (CUI/CIF) românesc. */
final class CuiValidator
{
    private const KEY = '753217532';

    public static function isValid(string $value): bool
    {
        $digits = preg_replace('/^RO/i', '', trim(str_replace(' ', '', $value)));
        if (! preg_match('/^\d{2,10}$/', $digits)) {
            return false;
        }
        $control = (int) substr($digits, -1);
        $body = str_pad(substr($digits, 0, -1), 9, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $body[$i] * (int) self::KEY[$i];
        }
        $expected = ($sum * 10) % 11;

        return ($expected === 10 ? 0 : $expected) === $control;
    }

    /** Completează cifra de control pentru un număr dat (folosit la generarea datelor de test). */
    public static function withControlDigit(string $body): string
    {
        $padded = str_pad($body, 9, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $padded[$i] * (int) self::KEY[$i];
        }
        $control = ($sum * 10) % 11;

        return $body.($control === 10 ? 0 : $control);
    }
}
