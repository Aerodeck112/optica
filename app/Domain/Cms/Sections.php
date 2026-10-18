<?php

namespace App\Domain\Cms;

use Illuminate\Support\Str;

/**
 * The section types an editor can use (CMS-01). "text" fields are per language,
 * "data" fields are shared by both languages.
 */
class Sections
{
    public const TYPES = [
        'hero' => ['text' => ['heading', 'body', 'button_label'], 'data' => ['image_id', 'button_target', 'show_phones']],
        'rich_text' => ['text' => ['heading', 'body'], 'data' => []],
        'text_image' => ['text' => ['heading', 'body', 'button_label'], 'data' => ['image_id', 'image_side', 'button_target']],
        'gallery' => ['text' => ['heading'], 'data' => ['image_ids']],
        'services' => ['text' => ['heading', 'body'], 'data' => []],
        'doctors' => ['text' => ['heading', 'body'], 'data' => []],
        'locations' => ['text' => ['heading', 'body'], 'data' => []],
        'offers' => ['text' => ['heading', 'body'], 'data' => []],
        'faq' => ['text' => ['heading', 'items'], 'data' => []],
        'cta' => ['text' => ['heading', 'body', 'button_label'], 'data' => ['button_target']],
        'contact_form' => ['text' => ['heading', 'body'], 'data' => ['lead_type']],
        'education' => ['text' => ['heading', 'body'], 'data' => ['tool', 'image_ids']],
    ];

    public static function make(string $type): array
    {
        $definition = self::TYPES[$type] ?? throw new \InvalidArgumentException($type);
        $text = [];
        foreach (config('optica.locales') as $locale) {
            $text[$locale] = array_fill_keys($definition['text'], '');
            if (in_array('items', $definition['text'], true)) {
                $text[$locale]['items'] = [];
            }
        }
        $data = array_fill_keys($definition['data'], null);
        if (array_key_exists('image_ids', $data)) {
            $data['image_ids'] = [];
        }
        if ($type === 'contact_form') {
            $data['lead_type'] = 'contact';
        }
        if ($type === 'text_image') {
            $data['image_side'] = 'right';
        }

        return ['id' => (string) Str::uuid(), 'type' => $type, 'text' => $text, 'data' => $data];
    }

    public static function emptyPage(string $titleRo = '', string $titleHu = ''): array
    {
        return [
            'title' => ['ro' => $titleRo, 'hu' => $titleHu],
            'seo' => ['ro' => ['title' => '', 'description' => '', 'noindex' => false], 'hu' => ['title' => '', 'description' => '', 'noindex' => false]],
            'og_image_id' => null,
            'sections' => [],
        ];
    }
}
