<?php

namespace App\Support;

/**
 * Normalizare pentru deduplicarea clienților și pentru lista de excludere.
 */
final class ContactNormalizer
{
    public static function email(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        $email = mb_strtolower(trim($email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * Normalizează la E.164. Numerele românești fără prefix (07xx…) primesc +40.
     */
    public static function phone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = preg_replace('/[^\d+]/', '', trim($phone));
        if ($digits === '' || $digits === null) {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }
        if (! str_starts_with($digits, '+')) {
            if (str_starts_with($digits, '0')) {
                $digits = '+40'.substr($digits, 1);
            } elseif (str_starts_with($digits, '40') && strlen($digits) === 11) {
                $digits = '+'.$digits;
            } else {
                return null;
            }
        }
        $digits = '+'.preg_replace('/\D/', '', $digits);

        return (strlen($digits) >= 9 && strlen($digits) <= 16) ? $digits : null;
    }

    public static function hash(string $channel, ?string $value): ?string
    {
        $normalized = $channel === 'sms' ? self::phone($value) : self::email($value);

        return $normalized ? hash('sha256', $channel.'|'.$normalized) : null;
    }

    public static function maskEmail(?string $email): string
    {
        if (! $email || ! str_contains($email, '@')) {
            return '—';
        }
        [$user, $domain] = explode('@', $email, 2);
        $dot = strrpos($domain, '.');
        $domainName = $dot === false ? $domain : substr($domain, 0, $dot);
        $tld = $dot === false ? '' : substr($domain, $dot);

        return mb_substr($user, 0, 2).'***@'.mb_substr($domainName, 0, 2).'***'.$tld;
    }

    public static function maskPhone(?string $phone): string
    {
        $normalized = self::phone($phone) ?? (string) $phone;

        return strlen($normalized) > 6 ? substr($normalized, 0, 4).str_repeat('•', strlen($normalized) - 7).substr($normalized, -3) : '—';
    }

    /** Redactează e-mailuri și telefoane dintr-un text liber (jurnale AI). */
    public static function redactText(string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $text);
        $text = preg_replace('/(\+?\d[\d\s().-]{7,}\d)/', '[telefon]', $text);

        return $text;
    }
}
