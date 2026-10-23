<?php

namespace App\Modules\Cms\Services;

use App\Modules\Cms\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Procesează imaginile încărcate din panou: orientare EXIF, eliminarea metadatelor (inclusiv GPS),
 * variante pe mai multe lățimi în WebP + JPEG (+ AVIF dacă serverul îl suportă), culoare dominantă.
 * Rulează sincron, cu limite de dimensiune, ca să funcționeze pe găzduire partajată fără worker permanent.
 */
class MediaProcessor
{
    public const WIDTHS = [480, 800, 1200, 1600, 2000];

    public const MAX_PIXELS = 40_000_000;

    public const ALLOWED_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const ALLOWED_VIDEO_MIMES = ['video/mp4', 'video/webm'];

    public static function supportsAvif(): bool
    {
        return function_exists('imageavif') && (gd_info()['AVIF Support'] ?? false);
    }

    /** Creează înregistrarea media dintr-un fișier deja salvat pe discul public (ex. încărcare Livewire). */
    public function createFromStoredFile(string $relativePath, string $originalName, array $attributes = [], ?int $userId = null): Media
    {
        $disk = Storage::disk('public');
        $absolute = $disk->path($relativePath);
        $mime = (string) mime_content_type($absolute);

        if (in_array($mime, self::ALLOWED_VIDEO_MIMES, true)) {
            return Media::query()->create($attributes + [
                'disk' => 'public',
                'directory' => dirname($relativePath),
                'filename' => basename($relativePath),
                'original_name' => Str::limit($originalName, 180, ''),
                'mime' => $mime,
                'kind' => 'video',
                'size' => filesize($absolute),
                'rights_status' => 'unknown',
                'uploaded_by' => $userId,
            ]);
        }

        if (! in_array($mime, self::ALLOWED_IMAGE_MIMES, true)) {
            $disk->delete($relativePath);
            throw new RuntimeException('Tip de fișier neacceptat. Sunt acceptate JPEG, PNG, WebP, MP4 și WebM.');
        }

        [$width, $height] = getimagesize($absolute) ?: [0, 0];
        if ($width * $height > self::MAX_PIXELS) {
            $disk->delete($relativePath);
            throw new RuntimeException('Imaginea este prea mare (peste 40 MP). Redimensionează și încarcă din nou.');
        }

        $media = Media::query()->create($attributes + [
            'disk' => 'public',
            'directory' => dirname($relativePath),
            'filename' => basename($relativePath),
            'original_name' => Str::limit($originalName, 180, ''),
            'mime' => $mime,
            'kind' => 'image',
            'size' => filesize($absolute),
            'width' => $width,
            'height' => $height,
            'focal_x' => 0.5,
            'focal_y' => 0.5,
            'rights_status' => 'unknown',
            'uploaded_by' => $userId,
        ]);

        return $this->generateVariants($media);
    }

    public function generateVariants(Media $media): Media
    {
        if (! $media->isImage() || $media->static_key) {
            return $media;
        }
        $disk = Storage::disk($media->disk);
        $source = $disk->path($media->directory.'/'.$media->filename);
        $manager = ImageManager::gd();
        $base = $manager->read($source)->orient();
        $origWidth = $base->width();

        // culoarea dominantă: media unei miniaturi 1×1
        $tiny = (clone $base)->resize(1, 1);
        $dominant = $tiny->pickColor(0, 0)->toHex('#');

        $this->deleteVariants($media);
        $variants = [];
        $formats = ['webp' => fn ($img) => $img->toWebp(quality: 78), 'jpg' => fn ($img) => $img->toJpeg(quality: 80, progressive: true, strip: true)];
        if (self::supportsAvif()) {
            $formats = ['avif' => fn ($img) => $img->toAvif(quality: 55)] + $formats;
        }
        $widths = array_values(array_filter(self::WIDTHS, fn ($w) => $w < $origWidth));
        $widths[] = min($origWidth, max(self::WIDTHS));
        $widths = array_values(array_unique($widths));

        $stem = pathinfo($media->filename, PATHINFO_FILENAME);
        foreach ($widths as $width) {
            $resized = (clone $base)->scaleDown(width: $width);
            foreach ($formats as $format => $encode) {
                $path = $media->directory."/v/{$stem}-{$width}.{$format}";
                $disk->put($path, (string) $encode($resized));
                $variants[] = ['format' => $format, 'width' => $resized->width(), 'height' => $resized->height(), 'path' => $path];
            }
        }

        // originalul se rescrie fără metadate EXIF (GPS, aparat) — păstrăm doar pixelii
        $disk->put($media->directory.'/'.$media->filename, (string) match ($media->mime) {
            'image/png' => $base->toPng(),
            'image/webp' => $base->toWebp(quality: 90),
            default => $base->toJpeg(quality: 90, strip: true),
        });

        $media->forceFill([
            'variants' => $variants,
            'width' => $base->width(),
            'height' => $base->height(),
            'dominant_color' => $dominant,
            'size' => $disk->size($media->directory.'/'.$media->filename),
        ])->save();

        return $media;
    }

    public function deleteFiles(Media $media): void
    {
        if ($media->static_key) {
            return; // fișierele statice fac parte din aplicație (public/media/static)
        }
        $this->deleteVariants($media);
        Storage::disk($media->disk)->delete($media->directory.'/'.$media->filename);
    }

    private function deleteVariants(Media $media): void
    {
        $disk = Storage::disk($media->disk);
        foreach ((array) $media->variants as $variant) {
            if (! empty($variant['path'])) {
                $disk->delete($variant['path']);
            }
        }
    }
}
