<?php

namespace App\Modules\Finance\Efactura;

use Illuminate\Support\Str;

/** Codurile de județ ISO 3166-2:RO cerute de CIUS-RO în adrese (CountrySubentity). */
final class CountyCodes
{
    public const CODES = [
        'RO-AB' => 'Alba', 'RO-AR' => 'Arad', 'RO-AG' => 'Argeș', 'RO-BC' => 'Bacău', 'RO-BH' => 'Bihor', 'RO-BN' => 'Bistrița-Năsăud',
        'RO-BT' => 'Botoșani', 'RO-BV' => 'Brașov', 'RO-BR' => 'Brăila', 'RO-B' => 'București', 'RO-BZ' => 'Buzău', 'RO-CS' => 'Caraș-Severin',
        'RO-CL' => 'Călărași', 'RO-CJ' => 'Cluj', 'RO-CT' => 'Constanța', 'RO-CV' => 'Covasna', 'RO-DB' => 'Dâmbovița', 'RO-DJ' => 'Dolj',
        'RO-GL' => 'Galați', 'RO-GR' => 'Giurgiu', 'RO-GJ' => 'Gorj', 'RO-HR' => 'Harghita', 'RO-HD' => 'Hunedoara', 'RO-IL' => 'Ialomița',
        'RO-IS' => 'Iași', 'RO-IF' => 'Ilfov', 'RO-MM' => 'Maramureș', 'RO-MH' => 'Mehedinți', 'RO-MS' => 'Mureș', 'RO-NT' => 'Neamț',
        'RO-OT' => 'Olt', 'RO-PH' => 'Prahova', 'RO-SM' => 'Satu Mare', 'RO-SJ' => 'Sălaj', 'RO-SB' => 'Sibiu', 'RO-SV' => 'Suceava',
        'RO-TR' => 'Teleorman', 'RO-TM' => 'Timiș', 'RO-TL' => 'Tulcea', 'RO-VS' => 'Vaslui', 'RO-VL' => 'Vâlcea', 'RO-VN' => 'Vrancea',
    ];

    public static function isValid(string $code): bool
    {
        return array_key_exists($code, self::CODES);
    }

    /** Acceptă codul (RO-CJ / CJ) sau numele județului, cu ori fără diacritice. */
    public static function fromName(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $upper = strtoupper($value);
        if (self::isValid($upper)) {
            return $upper;
        }
        if (self::isValid('RO-'.$upper)) {
            return 'RO-'.$upper;
        }
        $needle = Str::lower(Str::ascii($value));
        foreach (self::CODES as $code => $name) {
            if (Str::lower(Str::ascii($name)) === $needle) {
                return $code;
            }
        }

        return null;
    }
}
