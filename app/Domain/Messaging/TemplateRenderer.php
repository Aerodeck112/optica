<?php

namespace App\Domain\Messaging;

/**
 * {variable} placeholders (TRD-05): unknown or misspelled variables are refused when a template is
 * saved, so a patient never receives "{pacient}" literally.
 */
final class TemplateRenderer
{
    /** @return list<string> placeholders used in the text */
    public static function placeholders(string $text): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $text, $m);

        return array_values(array_unique($m[1]));
    }

    /** @return list<string> placeholders that the type does not allow, or unbalanced braces */
    public static function invalid(string $type, string $text): array
    {
        $invalid = array_values(array_diff(self::placeholders($text), MessageTypes::variables($type)));
        // A lone or malformed brace ("{date", "{Date}") is also an error.
        $stripped = preg_replace('/\{[a-z_]+\}/', '', $text);
        if (str_contains($stripped, '{') || str_contains($stripped, '}')) {
            $invalid[] = '{…}';
        }

        return $invalid;
    }

    public static function render(string $text, array $vars): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : '', $text);
    }

    /** SMS without diacritics, when the template asks for it (SMS-01). */
    public static function ascii(string $text): string
    {
        return strtr($text, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
            'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T',
            'á' => 'a', 'í' => 'i', 'ó' => 'o', 'ő' => 'o', 'ú' => 'u', 'ű' => 'u',
            'Á' => 'A', 'Í' => 'I', 'Ó' => 'O', 'Ő' => 'O', 'Ú' => 'U', 'Ű' => 'U',
            '„' => '"', '”' => '"', '“' => '"', '–' => '-', '—' => '-', '…' => '...',
        ]);
    }
}
