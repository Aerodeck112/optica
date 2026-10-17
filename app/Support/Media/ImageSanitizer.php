<?php

namespace App\Support\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Re-encodes uploaded images with GD: strips metadata (EXIF, GPS), drops anything that is not
 * pixel data, and limits the size (CMS-03, SEC-03). The original upload is never served.
 */
class ImageSanitizer
{
    public function storePublic(UploadedFile $file, string $directory, int $maxSide = 1200): string
    {
        $info = @getimagesize($file->getRealPath());
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new RuntimeException('Unsupported image');
        }

        $source = match ($info[2]) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($file->getRealPath()),
            IMAGETYPE_PNG => imagecreatefrompng($file->getRealPath()),
            IMAGETYPE_WEBP => imagecreatefromwebp($file->getRealPath()),
        };
        [$width, $height] = [$info[0], $info[1]];
        $scale = min(1, $maxSide / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagewebp($canvas, null, 82);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);
        imagedestroy($source);

        $path = trim($directory, '/').'/'.Str::uuid().'.webp';
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }
}
