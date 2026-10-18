<?php

namespace App\Domain\Cms;

use App\Models\Media;
use App\Support\Ui\SiteUrl;

/**
 * Helpers for section templates: per-language text with Romanian fallback, images, links.
 */
class SectionView
{
    /** @var array<int, Media|null> */
    private static array $media = [];

    public static function text(array $section, string $field): mixed
    {
        $locale = app()->getLocale();
        $value = $section['text'][$locale][$field] ?? null;
        if ($value === null || $value === '' || $value === []) {
            $value = $section['text']['ro'][$field] ?? null;
        }

        return $value;
    }

    public static function media(?int $id): ?Media
    {
        if (! $id) {
            return null;
        }

        return self::$media[$id] ??= Media::find($id);
    }

    /** "booking", "page:<key>" or an absolute/relative URL. */
    public static function link(?string $target): ?string
    {
        if (! $target) {
            return null;
        }
        if ($target === 'booking') {
            return SiteUrl::route('booking');
        }
        if (str_starts_with($target, 'page:')) {
            $page = \App\Models\Page::where('key', substr($target, 5))->first();

            return $page ? SiteUrl::path($page->slug()) : null;
        }
        if (str_starts_with($target, '/') || preg_match('#^https?://#', $target)) {
            return $target;
        }

        return null;
    }
}
