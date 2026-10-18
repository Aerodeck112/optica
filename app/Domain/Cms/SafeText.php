<?php

namespace App\Domain\Cms;

use Illuminate\Support\HtmlString;

/**
 * Editors write plain text: blank lines make paragraphs, lines starting with "- " make lists,
 * **text** is bold. Everything is escaped first, so no HTML or script can be injected (SEC-02).
 */
class SafeText
{
    public static function render(?string $text, string $paragraphClass = ''): HtmlString
    {
        $text = trim(str_replace("\r\n", "\n", (string) $text));
        if ($text === '') {
            return new HtmlString('');
        }

        $html = '';
        foreach (preg_split("/\n{2,}/", $text) as $block) {
            $lines = explode("\n", $block);
            if (collect($lines)->every(fn ($l) => str_starts_with(ltrim($l), '- '))) {
                $html .= '<ul class="list-disc space-y-1 pl-5">';
                foreach ($lines as $line) {
                    $html .= '<li>'.self::inline(substr(ltrim($line), 2)).'</li>';
                }
                $html .= '</ul>';
            } else {
                $html .= '<p'.($paragraphClass ? ' class="'.e($paragraphClass).'"' : '').'>'.implode('<br>', array_map([self::class, 'inline'], $lines)).'</p>';
            }
        }

        return new HtmlString($html);
    }

    private static function inline(string $text): string
    {
        $escaped = e($text);

        return preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped);
    }
}
