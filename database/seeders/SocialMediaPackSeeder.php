<?php

namespace Database\Seeders;

use App\Modules\Cms\Models\Media;
use Illuminate\Database\Seeder;

/**
 * Pachetul de fotografii pentru rețelele sociale (resources/data/social-pack.json, fișiere în public/media/social),
 * selectate din materialele francizei Another World. Adaugă și etichete tematice imaginilor din bibliotecă,
 * folosite la alegerea automată a pozelor în planul de postări.
 */
class SocialMediaPackSeeder extends Seeder
{
    /** Etichete pentru imaginile existente din biblioteca site-ului (după cheie). */
    private const LIBRARY_TAGS = [
        'logo-wall' => ['logo'],
        'headsets-row' => ['product'],
        'headsets-shelf' => ['product'],
        'arena-purple' => ['product'],
        'headset-product' => ['product'],
        'players-duo-red' => ['friends', 'logo'],
        'player-woman-red' => ['solo', 'action'],
        'player-reach' => ['solo', 'action'],
        'players-action' => ['group', 'action'],
        'players-team' => ['group', 'logo'],
        'friends-after-game' => ['friends', 'after'],
        'couple-after-game' => ['couple', 'friends', 'after'],
        'group-arena' => ['group', 'friends', 'action'],
        'group-logo' => ['group', 'friends', 'logo'],
        'friends-floor' => ['friends', 'after', 'fun'],
        'player-logo' => ['solo', 'logo'],
        'duo-cosmic' => ['friends', 'action'],
        'corporate-duo' => ['corporate', 'logo'],
        'kid-player-red' => ['kids', 'birthday', 'action'],
        'kid-player-blue' => ['kids', 'birthday', 'action'],
        'kids-team' => ['kids', 'birthday', 'group'],
        'teen-smile' => ['teens', 'after'],
        'briefing-headset' => ['briefing'],
        'teens-duo' => ['teens', 'friends', 'logo'],
    ];

    public function run(): void
    {
        $pack = json_decode((string) file_get_contents(resource_path('data/social-pack.json')), true);

        foreach ((array) ($pack['images'] ?? []) as $image) {
            Media::query()->updateOrCreate(['static_key' => $image['key']], [
                'disk' => 'public_root',
                'directory' => 'media/social',
                'filename' => "{$image['file']}-{$image['width']}.jpg",
                'original_name' => basename($image['file']).'.jpg',
                'mime' => 'image/jpeg',
                'kind' => 'image',
                'width' => $image['width'],
                'height' => $image['height'],
                'dominant_color' => $image['color'],
                'alt' => ['ro' => $image['alt_ro']],
                'category' => $image['category'] ?? 'network_photo',
                'source' => $image['drive_id'] ? 'Google Drive franciză Another World — '.$image['source'].' (ID '.$image['drive_id'].')' : $image['source'],
                'rights_status' => 'licensed_franchise',
                'rights_notes' => ($image['rights_notes'] ?? 'Material de marketing furnizat de franciza Another World (altă locație a rețelei). Selectat pentru rețelele sociale.')
                    .($image['minors'] ? ' Apar minori: acordul pentru publicare a fost confirmat de proprietar (01.11.2026).' : ''),
                'depicts_minors' => (bool) $image['minors'],
                'guardian_consent_confirmed' => (bool) $image['minors'],
                'tags' => $image['tags'],
                'variants' => [
                    ['format' => 'jpg', 'width' => $image['thumb_width'], 'height' => $image['thumb_height'], 'path' => "media/social/{$image['file']}-{$image['thumb_width']}.jpg"],
                    ['format' => 'jpg', 'width' => $image['width'], 'height' => $image['height'], 'path' => "media/social/{$image['file']}-{$image['width']}.jpg"],
                ],
            ]);
        }

        foreach (Media::query()->whereNotNull('static_key')->whereNull('tags')->get() as $media) {
            $tags = self::LIBRARY_TAGS[$media->static_key] ?? match (true) {
                str_starts_with((string) $media->static_key, 'game-') => ['games'],
                $media->category === 'brand_art' => ['brand'],
                default => null,
            };
            if ($tags) {
                $media->forceFill(['tags' => $tags])->save();
            }
        }
    }
}
