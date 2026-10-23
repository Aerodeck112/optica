<?php

namespace App\Modules\Cms\Services;

use Illuminate\Support\Str;

/**
 * Markdown fără HTML brut și fără linkuri nesigure. Folosit pentru toate textele introduse în CMS.
 */
final class SafeMarkdown
{
    public static function render(?string $markdown): string
    {
        if ($markdown === null || trim($markdown) === '') {
            return '';
        }

        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 10,
        ]);

        // linkurile externe se deschid în aceeași fereastră, dar fără a transmite referrer-ul complet
        return preg_replace('/<a href="(https?:\/\/[^"]+)"/i', '<a href="$1" rel="noopener"', $html);
    }

    public static function inline(?string $markdown): string
    {
        if ($markdown === null || trim($markdown) === '') {
            return '';
        }

        return Str::inlineMarkdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    public static function plain(?string $markdown, int $limit = 300): string
    {
        return Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags(self::render($markdown)))), $limit);
    }
}
