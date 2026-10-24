<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\Booking;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Codul QR de check-in: „AW1:<cod rezervare>:<semnătură>”. Semnătura HMAC (cheia aplicației) împiedică
 * generarea de coduri valide fără acces la sistem; codul nu conține date personale.
 */
final class CheckinCode
{
    public static function payload(Booking $booking): string
    {
        return 'AW1:'.$booking->code.':'.self::signature($booking->code);
    }

    /** Întoarce codul rezervării dacă textul scanat este un QR valid sau un cod de rezervare tastat. */
    public static function resolve(string $input): ?string
    {
        $input = strtoupper(trim($input));
        if (preg_match('/^AW1:(AW[A-Z0-9]{7}):([A-F0-9]{16})$/', $input, $m)) {
            return hash_equals(strtoupper(self::signature($m[1])), $m[2]) ? $m[1] : null;
        }
        if (preg_match('/^AW[A-Z0-9]{7}$/', $input)) {
            return $input;
        }

        return null;
    }

    public static function svg(Booking $booking, int $size = 220): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size, 2, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(5, 4, 15))),
            new SvgImageBackEnd
        );

        return (new Writer($renderer))->writeString(self::payload($booking));
    }

    private static function signature(string $code): string
    {
        return substr(hash_hmac('sha256', 'checkin|'.$code, (string) config('app.key')), 0, 16);
    }
}
