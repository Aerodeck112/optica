<?php

namespace App\Domain\Messaging;

/**
 * SMS length (SMS-01). GSM-7 allows 160 characters (153 per part when split); any character outside
 * it – including ă, ș, ț, ő, ű – switches the whole message to UCS-2: 70 characters (67 per part).
 */
final class SmsSegments
{
    private const GSM7 = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM7_EXTENDED = "^{}\\[~]|€\f";

    /** @return array{encoding: string, length: int, segments: int, per_segment: int, non_gsm: list<string>} */
    public static function analyse(string $text): array
    {
        $chars = mb_str_split($text);
        $nonGsm = [];
        $length = 0;
        foreach ($chars as $char) {
            if (mb_strpos(self::GSM7, $char) !== false) {
                $length++;
            } elseif (mb_strpos(self::GSM7_EXTENDED, $char) !== false) {
                $length += 2;
            } else {
                $nonGsm[] = $char;
            }
        }

        if ($nonGsm) {
            // UCS-2 counts UTF-16 code units: characters outside the BMP (emoji) count twice.
            $units = strlen(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')) / 2;

            return ['encoding' => 'UCS-2', 'length' => (int) $units, 'segments' => $units <= 70 ? 1 : (int) ceil($units / 67),
                'per_segment' => $units <= 70 ? 70 : 67, 'non_gsm' => array_values(array_unique($nonGsm))];
        }

        return ['encoding' => 'GSM-7', 'length' => $length, 'segments' => $length <= 160 ? 1 : (int) ceil($length / 153),
            'per_segment' => $length <= 160 ? 160 : 153, 'non_gsm' => []];
    }
}
