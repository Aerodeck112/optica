<?php

namespace App\Modules\Marketing\Services\Social;

use App\Modules\Cms\Models\Media;
use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Pregătește imaginile din bibliotecă în formatele cerute de rețele (JPEG, sRGB, fără metadate).
 * Decuparea pornește de la punctul focal al imaginii; dacă decuparea ar pierde prea mult din fotografie
 * (ex. o poză lată pusă în story), imaginea întreagă se așază peste o variantă estompată a ei.
 */
class SocialImageRenderer
{
    /** @var array<string, array{width:int, height:int, label:string}> */
    public const FORMATS = [
        'portrait' => ['width' => 1080, 'height' => 1350, 'label' => 'Instagram 4:5'],
        'square' => ['width' => 1080, 'height' => 1080, 'label' => 'Pătrat 1:1'],
        'story' => ['width' => 1080, 'height' => 1920, 'label' => 'Story 9:16'],
        'original' => ['width' => 2048, 'height' => 2048, 'label' => 'Original'],
    ];

    /** Sub această proporție păstrată din imagine, decuparea este înlocuită de așezarea pe fundal estompat. */
    private const MIN_KEPT_RATIO = 0.6;

    public function render(Media $media, string $format): string
    {
        if (! isset(self::FORMATS[$format])) {
            throw new RuntimeException('Format necunoscut.');
        }
        $source = $this->load($media);
        $target = self::FORMATS[$format];

        $image = $format === 'original'
            ? $this->scaleDown($source, $target['width'])
            : $this->compose($source, $target['width'], $target['height'], (float) $media->focal_x, (float) $media->focal_y);
        imagedestroy($source);

        ob_start();
        imageinterlace($image, true);
        imagejpeg($image, null, 88);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    public function filename(Media $media, string $format): string
    {
        $name = $media->static_key ?: pathinfo((string) $media->original_name, PATHINFO_FILENAME) ?: 'imagine-'.$media->id;

        return 'aw-'.str($name)->slug()->limit(60, '').'-'.$format.'.jpg';
    }

    private function load(Media $media): GdImage
    {
        if (! $media->isImage()) {
            throw new RuntimeException('Fișierul nu este o imagine.');
        }
        // cea mai mare variantă JPEG/WebP (GD nu citește AVIF peste tot)
        $best = null;
        foreach ((array) $media->variants as $variant) {
            if (in_array($variant['format'] ?? null, ['jpg', 'webp'], true) && (! $best || $variant['width'] > $best['width'])) {
                $best = $variant;
            }
        }
        $path = $best['path'] ?? trim($media->directory.'/'.$media->filename, '/');
        $bytes = Storage::disk($media->disk)->get($path);
        $image = $bytes !== null ? @imagecreatefromstring($bytes) : false;
        if (! $image) {
            throw new RuntimeException('Imaginea nu a putut fi citită de pe server.');
        }

        return $image;
    }

    private function compose(GdImage $source, int $width, int $height, float $focalX, float $focalY): GdImage
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        $scale = max($width / $sw, $height / $sh);
        $kept = min(($width / $scale) / $sw, ($height / $scale) / $sh);

        $canvas = imagecreatetruecolor($width, $height);
        if ($kept >= self::MIN_KEPT_RATIO) {
            $this->cover($canvas, $source, $width, $height, $focalX, $focalY);

            return $canvas;
        }

        // fundal: aceeași imagine, mărită, estompată și întunecată
        $this->cover($canvas, $source, $width, $height, $focalX, $focalY);
        $small = imagecreatetruecolor(max(1, intdiv($width, 24)), max(1, intdiv($height, 24)));
        imagecopyresampled($small, $canvas, 0, 0, 0, 0, imagesx($small), imagesy($small), $width, $height);
        imagecopyresampled($canvas, $small, 0, 0, 0, 0, $width, $height, imagesx($small), imagesy($small));
        imagedestroy($small);
        imagefilter($canvas, IMG_FILTER_GAUSSIAN_BLUR);
        imagefilter($canvas, IMG_FILTER_BRIGHTNESS, -60);

        // imaginea întreagă, centrată
        $fit = min($width / $sw, $height / $sh);
        $fw = (int) round($sw * $fit);
        $fh = (int) round($sh * $fit);
        imagecopyresampled($canvas, $source, intdiv($width - $fw, 2), intdiv($height - $fh, 2), 0, 0, $fw, $fh, $sw, $sh);

        return $canvas;
    }

    private function cover(GdImage $canvas, GdImage $source, int $width, int $height, float $focalX, float $focalY): void
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        $scale = max($width / $sw, $height / $sh);
        $cw = $width / $scale;
        $ch = $height / $scale;
        $x = (int) round(max(0, min($sw - $cw, $sw * ($focalX ?: 0.5) - $cw / 2)));
        $y = (int) round(max(0, min($sh - $ch, $sh * ($focalY ?: 0.5) - $ch / 2)));
        imagecopyresampled($canvas, $source, 0, 0, $x, $y, $width, $height, (int) round($cw), (int) round($ch));
    }

    private function scaleDown(GdImage $source, int $maxSide): GdImage
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        $ratio = min(1, $maxSide / max($sw, $sh));
        $w = (int) round($sw * $ratio);
        $h = (int) round($sh * $ratio);
        $image = imagecreatetruecolor($w, $h);
        imagecopyresampled($image, $source, 0, 0, 0, 0, $w, $h, $sw, $sh);

        return $image;
    }
}
