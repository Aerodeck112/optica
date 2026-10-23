<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Sumele sunt păstrate ca întregi în subunități (bani). Calculele cu procente folosesc BigDecimal.
 */
final class Money
{
    public static function format(?int $minor, string $currency = 'RON', bool $withCurrency = true): string
    {
        if ($minor === null) {
            return '';
        }

        $negative = $minor < 0;
        $abs = abs($minor);
        $units = intdiv($abs, 100);
        $cents = $abs % 100;
        $formatted = number_format($units, 0, ',', '.').($cents ? ','.str_pad((string) $cents, 2, '0', STR_PAD_LEFT) : '');

        if ($withCurrency) {
            $formatted .= ' '.($currency === 'RON' ? 'lei' : $currency);
        }

        return ($negative ? '−' : '').$formatted;
    }

    /** Transformă „123,45” / „123.45” / „123” în bani. */
    public static function parse(string|int|float|null $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value * 100;
        }
        $normalized = str_replace([' ', "\u{00A0}"], '', (string) $value);
        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
        }
        $normalized = str_replace(',', '.', $normalized);

        return BigDecimal::of($normalized)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    public static function toDecimalString(int $minor): string
    {
        return BigDecimal::ofUnscaledValue($minor, 2)->__toString();
    }

    /** Procent din sumă, rotunjit la ban (ex: avans 30%). $percentBasisPoints: 3000 = 30,00%. */
    public static function percentOf(int $minor, int $percentBasisPoints): int
    {
        return BigDecimal::of($minor)
            ->multipliedBy($percentBasisPoints)
            ->dividedBy(10000, 0, RoundingMode::HalfUp)
            ->toInt();
    }
}
