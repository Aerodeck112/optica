<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Crm\Models\Customer;

/**
 * Link scurt de dezabonare pentru SMS (un link semnat complet ar costa segmente SMS în plus).
 * Formatul /u/{id}{canal}{semnătură} este verificat cu HMAC (cheia aplicației).
 */
final class ShortUnsubscribeLink
{
    public static function url(Customer $customer, string $channel = 'sms'): string
    {
        return url('/u/'.self::token($customer->id, $channel));
    }

    public static function token(int $customerId, string $channel): string
    {
        $prefix = base_convert((string) $customerId, 10, 36).($channel === 'sms' ? 's' : 'e');

        return $prefix.self::signature($prefix);
    }

    /** @return array{0:int,1:string}|null */
    public static function resolve(string $token): ?array
    {
        if (! preg_match('/^([0-9a-z]+)([se])([0-9a-z]{8})$/', $token, $m)) {
            return null;
        }
        if (! hash_equals(self::signature($m[1].$m[2]), $m[3])) {
            return null;
        }

        return [(int) base_convert($m[1], 36, 10), $m[2] === 's' ? 'sms' : 'email'];
    }

    private static function signature(string $prefix): string
    {
        return substr(base_convert(substr(hash_hmac('sha256', 'unsub|'.$prefix, (string) config('app.key')), 0, 12), 16, 36), 0, 8);
    }
}
