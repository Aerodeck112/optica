<?php

namespace App\Domain\Cms;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Image library (CMS-03): every upload is re-encoded (metadata removed, never executable),
 * stored with responsive WebP variants, and checked for usage before deletion.
 */
class MediaLibrary
{
    public const WIDTHS = [480, 960, 1600];

    public function storeUpload(UploadedFile $file, array $alt = [], ?int $userId = null): Media
    {
        return $this->storeFile($file->getRealPath(), $file->getClientOriginalName(), $alt, $userId);
    }

    public function storeFile(string $sourcePath, ?string $originalName = null, array $alt = [], ?int $userId = null): Media
    {
        $info = @getimagesize($sourcePath);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new RuntimeException('Unsupported image');
        }
        $source = match ($info[2]) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => imagecreatefromwebp($sourcePath),
        };
        [$width, $height] = [$info[0], $info[1]];
        $base = 'media/'.now()->format('Y/m').'/'.Str::uuid();

        $variants = [];
        $main = null;
        foreach (self::WIDTHS as $target) {
            $w = min($target, $width);
            $path = $base.'-'.$w.'.webp';
            Storage::disk('public')->put($path, $this->encode($source, $width, $height, $w));
            $variants[$w] = $path;
            $main = $path;
            if ($w === $width) {
                break;
            }
        }
        imagedestroy($source);

        return Media::create([
            'path' => $main,
            'variants' => $variants,
            'original_name' => $originalName ? mb_substr(basename($originalName), 0, 255) : null,
            'mime' => 'image/webp',
            'size' => (int) Storage::disk('public')->size($main),
            'width' => min($width, max(self::WIDTHS)),
            'height' => (int) round($height * min($width, max(self::WIDTHS)) / $width),
            'alt' => $alt + ['ro' => '', 'hu' => ''],
            'uploaded_by' => $userId,
        ]);
    }

    /** Where an image is used, so it is not deleted while in use (CMS-03). */
    public function usages(Media $media): array
    {
        $id = $media->id;
        $usages = [];
        foreach (DB::table('pages')->whereNull('deleted_at')->get(['key', 'draft', 'published']) as $page) {
            foreach (['draft', 'published'] as $column) {
                if ($page->{$column} && $this->jsonReferences($page->{$column}, $id)) {
                    $usages[] = 'page:'.$page->key;
                    break;
                }
            }
        }
        foreach (['posts' => 'image_id', 'locations' => 'image_id', 'services' => 'image_id'] as $table => $column) {
            if (DB::table($table)->where($column, $id)->exists()) {
                $usages[] = $table;
            }
        }

        return array_values(array_unique($usages));
    }

    public function delete(Media $media): bool
    {
        if ($this->usages($media)) {
            return false;
        }
        Storage::disk('public')->delete(array_values($media->variants ?? [$media->path]));
        $media->delete();

        return true;
    }

    private function jsonReferences(string $json, int $id): bool
    {
        return (bool) preg_match('/"(?:image_id|image_ids|og_image_id)":\s*(?:\[[^\]]*\b'.$id.'\b[^\]]*\]|"?'.$id.'"?)(?=[,}\]])/', $json);
    }

    private function encode($source, int $width, int $height, int $targetWidth): string
    {
        $targetHeight = (int) round($height * $targetWidth / $width);
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        ob_start();
        imagewebp($canvas, null, 80);
        imagedestroy($canvas);

        return (string) ob_get_clean();
    }
}
