<?php

namespace App\Domain\Messaging;

use App\Models\Patient;

/** Recipient addresses in the form each provider expects. */
final class Contacts
{
    /** E.164 ("+40712345678") from any typed format, or null. */
    public static function phone(?string $phone): ?string
    {
        $digits = Patient::normalizePhone($phone);

        return $digits && strlen($digits) >= 10 && strlen($digits) <= 15 ? '+'.$digits : null;
    }

    public static function email(?string $email): ?string
    {
        $email = $email ? mb_strtolower(trim($email)) : null;

        return $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public static function forChannel(string $channel, ?string $phone, ?string $email): ?string
    {
        return $channel === 'email' ? self::email($email) : self::phone($phone);
    }
}
