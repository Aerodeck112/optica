<?php

namespace Database\Seeders;

use App\Modules\Cms\Models\Media;
use Illuminate\Database\Seeder;

/**
 * Importă în biblioteca media asset-urile generate de tools/assets/build.py (imagini) și materialele video din public/media/video.
 * Fiecare intrare păstrează sursa (ID-ul fișierului din Drive-ul francizei) și drepturile cunoscute.
 */
class MediaLibrarySeeder extends Seeder
{
    private const CATEGORY = ['network-photo' => 'network_photo', 'game-art' => 'game_art', 'brand-art' => 'brand_art'];

    public function run(): void
    {
        $catalog = json_decode(file_get_contents(resource_path('data/asset-catalog.json')), true);

        foreach ($catalog['images'] as $image) {
            $variants = [];
            foreach ($image['variants'] as $v) {
                foreach (['avif', 'webp'] as $format) {
                    $variants[] = ['format' => $format, 'width' => $v['width'], 'height' => $v['height'], 'path' => "media/static/{$image['key']}-{$v['width']}.{$format}"];
                }
            }
            $fallback = collect($image['variants'])->firstWhere('width', $image['fallback_width']) ?? end($image['variants']);
            $variants[] = ['format' => 'jpg', 'width' => $fallback['width'], 'height' => $fallback['height'], 'path' => "media/static/{$image['key']}-{$fallback['width']}.jpg"];
            $largest = end($image['variants']);

            Media::query()->updateOrCreate(['static_key' => $image['key']], [
                'disk' => 'public_root',
                'directory' => 'media/static',
                'filename' => "{$image['key']}-{$fallback['width']}.jpg",
                'original_name' => basename($image['source']['path']),
                'mime' => 'image/jpeg',
                'kind' => 'image',
                'width' => $largest['width'],
                'height' => $largest['height'],
                'dominant_color' => $image['color'],
                'alt' => ['ro' => $image['alt_ro']],
                'category' => self::CATEGORY[$image['kind']] ?? 'other',
                'source' => 'Google Drive franciză Another World — '.$image['source']['path'].' (ID '.$image['source']['drive_id'].')',
                'rights_status' => 'licensed_franchise',
                'rights_notes' => $image['rights'],
                'depicts_minors' => str_contains($image['rights'], 'minori'),
                // acordul părinților pentru imaginile francizei a fost confirmat de proprietar (24.10.2026)
                'guardian_consent_confirmed' => str_contains($image['rights'], 'minori'),
                'variants' => $variants,
            ]);
        }

        // Posterul portalului (cadru din filmul de brand)
        $poster = Media::query()->updateOrCreate(['static_key' => 'portal-poster'], [
            'disk' => 'public_root',
            'directory' => 'media/video',
            'filename' => 'portal-poster-900.jpg',
            'original_name' => 'Another World.mkv (cadru)',
            'mime' => 'image/jpeg',
            'kind' => 'image',
            'width' => 900,
            'height' => 720,
            'dominant_color' => '#1a2340',
            'alt' => ['ro' => 'Nori luminați deasupra unei planete, cadru din filmul de prezentare Another World'],
            'category' => 'brand_art',
            'source' => 'Film de prezentare „Another World.mkv” din Drive-ul francizei (Game materials)',
            'rights_status' => 'licensed_franchise',
            'rights_notes' => 'Randare din filmul de brand Another World. Nu reprezintă arena din Cluj.',
            'variants' => [
                ['format' => 'avif', 'width' => 600, 'height' => 480, 'path' => 'media/video/portal-poster-600.avif'],
                ['format' => 'avif', 'width' => 900, 'height' => 720, 'path' => 'media/video/portal-poster-900.avif'],
                ['format' => 'webp', 'width' => 600, 'height' => 480, 'path' => 'media/video/portal-poster-600.webp'],
                ['format' => 'webp', 'width' => 900, 'height' => 720, 'path' => 'media/video/portal-poster-900.webp'],
                ['format' => 'jpg', 'width' => 900, 'height' => 720, 'path' => 'media/video/portal-poster-900.jpg'],
            ],
        ]);

        Media::query()->updateOrCreate(['static_key' => 'portal-loop'], [
            'disk' => 'public_root',
            'directory' => 'media/video',
            'filename' => 'portal-loop.mp4',
            'original_name' => 'Another World.mkv (montaj 17 s)',
            'mime' => 'video/mp4',
            'kind' => 'video',
            'duration_seconds' => 17,
            'width' => 720,
            'height' => 576,
            'alt' => ['ro' => 'Montaj de peisaje fantastice din filmul de prezentare Another World'],
            'category' => 'brand_art',
            'source' => 'Montaj din „Another World.mkv” (scene fără echipament: nori, stânci, oraș, peșteră, meduze, deșert, portal)',
            'rights_status' => 'licensed_franchise',
            'rights_notes' => 'Randări de brand. Folosit fără sunet, în buclă, în hero.',
            'poster_id' => $poster->id,
        ]);

        $trailers = [
            'kernel-bunker' => 'Trailer Kernel: Bunker (engleză)',
            'starbase-expedition' => 'Trailer Starbase: Expedition (engleză)',
            'ghost-tower-miners' => 'Trailer Mysterious Tower: Miners (fără text)',
            'ghost-tower' => 'Trailer Ghost Tower (engleză)',
            'shmooter' => 'Trailer Shmooter (engleză)',
            'colony-code-red' => 'Teaser Colony: Code Red',
        ];
        foreach ($trailers as $key => $label) {
            $trailerPoster = Media::query()->updateOrCreate(['static_key' => "trailer-{$key}-poster"], [
                'disk' => 'public_root',
                'directory' => 'media/video',
                'filename' => "trailer-{$key}-poster-1280.webp",
                'mime' => 'image/webp',
                'kind' => 'image',
                'width' => 1280,
                'height' => 720,
                'alt' => ['ro' => 'Cadru din '.$label],
                'category' => 'game_art',
                'source' => 'Cadru din trailerul oficial, Drive franciză (Game materials)',
                'rights_status' => 'licensed_franchise',
                'rights_notes' => 'Material oficial al jocului furnizat de franciză.',
                'variants' => [
                    ['format' => 'avif', 'width' => 640, 'height' => 360, 'path' => "media/video/trailer-{$key}-poster-640.avif"],
                    ['format' => 'avif', 'width' => 1280, 'height' => 720, 'path' => "media/video/trailer-{$key}-poster-1280.avif"],
                    ['format' => 'webp', 'width' => 640, 'height' => 360, 'path' => "media/video/trailer-{$key}-poster-640.webp"],
                    ['format' => 'webp', 'width' => 1280, 'height' => 720, 'path' => "media/video/trailer-{$key}-poster-1280.webp"],
                ],
            ]);
            Media::query()->updateOrCreate(['static_key' => "trailer-{$key}"], [
                'disk' => 'public_root',
                'directory' => 'media/video',
                'filename' => "trailer-{$key}.mp4",
                'mime' => 'video/mp4',
                'kind' => 'video',
                'width' => 960,
                'height' => 540,
                'alt' => ['ro' => $label],
                'category' => 'game_art',
                'source' => 'Trailer oficial, Drive franciză (Game materials), redus la 30 s / 960p',
                'rights_status' => 'licensed_franchise',
                'rights_notes' => 'Material oficial al jocului furnizat de franciză.',
                'poster_id' => $trailerPoster->id,
            ]);
        }
    }
}
