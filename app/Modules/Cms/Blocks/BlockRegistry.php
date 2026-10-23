<?php

namespace App\Modules\Cms\Blocks;

/**
 * Lista închisă a blocurilor disponibile în editor. Fiecare bloc are un șablon Blade în resources/views/site/blocks.
 * Nu există bloc de HTML/JS liber: conținutul se introduce prin câmpuri validate.
 */
final class BlockRegistry
{
    public const BLOCKS = [
        'hero_portal' => ['label' => 'Hero cu portal', 'icon' => 'heroicon-o-sparkles'],
        'statement' => ['label' => 'Mesaj mare', 'icon' => 'heroicon-o-megaphone'],
        'audience_paths' => ['label' => 'Pentru cine este (categorii)', 'icon' => 'heroicon-o-user-group'],
        'game_rail' => ['label' => 'Jocuri (bandă orizontală)', 'icon' => 'heroicon-o-puzzle-piece'],
        'feature_split' => ['label' => 'Text + imagine', 'icon' => 'heroicon-o-photo'],
        'steps' => ['label' => 'Pași (cum funcționează)', 'icon' => 'heroicon-o-list-bullet'],
        'image_band' => ['label' => 'Bandă imagine cinematografică', 'icon' => 'heroicon-o-film'],
        'gallery_mosaic' => ['label' => 'Galerie', 'icon' => 'heroicon-o-squares-2x2'],
        'testimonials' => ['label' => 'Recenzii reale', 'icon' => 'heroicon-o-chat-bubble-left-right'],
        'faq_list' => ['label' => 'Întrebări frecvente', 'icon' => 'heroicon-o-question-mark-circle'],
        'packages_table' => ['label' => 'Pachete și tarife', 'icon' => 'heroicon-o-currency-euro'],
        'lead_form' => ['label' => 'Formular cerere ofertă', 'icon' => 'heroicon-o-envelope'],
        'contact_details' => ['label' => 'Date de contact și program', 'icon' => 'heroicon-o-map-pin'],
        'contact_form' => ['label' => 'Formular de contact', 'icon' => 'heroicon-o-chat-bubble-left'],
        'notice' => ['label' => 'Informare importantă', 'icon' => 'heroicon-o-information-circle'],
        'rich_text' => ['label' => 'Text', 'icon' => 'heroicon-o-document-text'],
        'cta_portal' => ['label' => 'Îndemn final la rezervare', 'icon' => 'heroicon-o-arrow-right-circle'],
        'news_list' => ['label' => 'Ultimele articole', 'icon' => 'heroicon-o-newspaper'],
    ];

    public static function types(): array
    {
        return array_keys(self::BLOCKS);
    }

    public static function exists(string $type): bool
    {
        return array_key_exists($type, self::BLOCKS);
    }

    public static function label(string $type): string
    {
        return self::BLOCKS[$type]['label'] ?? $type;
    }
}
