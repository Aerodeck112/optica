<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Amounts are integer bani. Input accepts "1234,50", "1.234,50", "1234.5"; display is Romanian
 * style "1.234,50 lei" in both interface languages (the currency is the same).
 */
final class Money
{
    public static function parse(string|int|float|null $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value * 100;
        }
        $text = str_replace([' ', "\u{00A0}", 'lei', 'RON'], '', trim((string) $value));
        // Thousands are grouped with dots only; the decimal separator is a comma (or a dot), max. 2 digits.
        // "12,345" is ambiguous and refused rather than guessed.
        if (preg_match('/^(-?)(\d{1,3}(?:\.\d{3})+|\d+)(?:[.,](\d{1,2}))?$/', $text, $m) !== 1) {
            throw new InvalidArgumentException('Invalid amount');
        }
        $whole = (int) str_replace(['.', ','], '', $m[2]);
        $fraction = isset($m[3]) ? (int) str_pad($m[3], 2, '0') : 0;
        $cents = $whole * 100 + $fraction;

        return $m[1] === '-' ? -$cents : $cents;
    }

    public static function isValid(mixed $value): bool
    {
        try {
            return self::parse(is_scalar($value) ? (string) $value : null) !== null;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public static function format(?int $cents, bool $currency = true): string
    {
        if ($cents === null) {
            return '—';
        }
        $text = number_format(abs($cents) / 100, 2, ',', '.');

        return ($cents < 0 ? '−' : '').$text.($currency ? ' lei' : '');
    }

    /** "1234,50" for an input field. */
    public static function input(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, ',', '');
    }
}
