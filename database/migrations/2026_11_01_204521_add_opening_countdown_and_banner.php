<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Deschiderea oficială: vineri, 13 noiembrie 2026 (confirmată de proprietar). Setează data, adaugă pe prima pagină
 * blocul cu numărătoarea inversă (imediat după hero) și un banner până la sfârșitul zilei deschiderii.
 */
return new class extends Migration
{
    public const BLOCK = ['type' => 'opening_countdown', 'data' => [
        'title_1' => 'O altă lume se deschide.',
        'title_2' => 'Chiar vineri, 13.',
        'body' => 'Deschidem arena Another World pe Str. Teodor Mihali nr. 31-35, în Cluj-Napoca. Adu-ți prietenii, familia sau colegii și intrați împreună în jocuri de realitate virtuală multiplayer.',
        'today_title' => 'Azi deschidem. Te așteptăm în altă lume.',
        'primary_label' => 'Rezervă pentru deschidere', 'primary_link' => 'route:booking',
        'secondary_label' => 'Vezi jocurile', 'secondary_link' => 'route:games',
    ]];

    /** Varianta în engleză; se adaugă doar dacă prima pagină are deja conținut în engleză. */
    public const BLOCK_EN = ['type' => 'opening_countdown', 'data' => [
        'title_1' => 'Another World opens.',
        'title_2' => 'Friday the 13th.',
        'body' => 'Reality takes a strange turn. The Another World VR arena opens at Str. Teodor Mihali 31-35, Cluj-Napoca. Bring your friends, family or colleagues and step into multiplayer virtual reality together.',
        'today_title' => 'We are open. Step into another world.',
        'primary_label' => 'Book for the opening', 'primary_link' => 'route:booking',
        'secondary_label' => 'See the games', 'secondary_link' => 'route:games',
    ]];

    public function up(): void
    {
        // doar pe un site deja instalat (cu prima pagină); o instalare nouă își setează singură data
        if (! DB::table('pages')->where('key', 'home')->exists()) {
            return;
        }

        $current = DB::table('settings')->where('key', 'venue.opening_date')->value('value');
        if ($current === null || trim((string) $current) === '') {
            DB::table('settings')->updateOrInsert(['key' => 'venue.opening_date'], ['value' => '2026-11-13', 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now()]);
        }

        $home = DB::table('pages')->where('key', 'home')->first();
        if ($home) {
            $changes = [];
            foreach (['blocks', 'draft_blocks'] as $column) {
                $blocks = json_decode((string) $home->{$column}, true);
                if (! is_array($blocks)) {
                    continue;
                }
                $changed = false;
                foreach (['ro' => self::BLOCK, 'en' => self::BLOCK_EN] as $locale => $block) {
                    if (! isset($blocks[$locale]) || ! is_array($blocks[$locale]) || collect($blocks[$locale])->contains(fn ($b) => ($b['type'] ?? null) === 'opening_countdown')) {
                        continue;
                    }
                    $position = ($blocks[$locale][0]['type'] ?? null) === 'hero_portal' ? 1 : 0;
                    array_splice($blocks[$locale], $position, 0, [$block]);
                    $changed = true;
                }
                if ($changed) {
                    $changes[$column] = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
            if ($changes) {
                DB::table('pages')->where('id', $home->id)->update($changes);
            }
        }

        if (! DB::table('banners')->where('name', 'Deschidere oficială 13.11')->exists()) {
            DB::table('banners')->insert([
                'name' => 'Deschidere oficială 13.11',
                'placement' => 'top',
                'text' => json_encode(['ro' => 'Deschidem vineri, 13 noiembrie, pe Str. Teodor Mihali nr. 31-35, Cluj-Napoca.'], JSON_UNESCAPED_UNICODE),
                'link_label' => json_encode(['ro' => 'Rezervă pentru deschidere'], JSON_UNESCAPED_UNICODE),
                'link_url' => 'route:booking',
                'tone' => 'promo',
                'is_dismissible' => true,
                'is_active' => true,
                'starts_at' => null,
                // sfârșitul zilei de 13 noiembrie, ora României (UTC+2)
                'ends_at' => '2026-11-13 21:59:59',
                'published_locales' => json_encode(['ro']),
                'sort' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::forget('aw.settings.v1');
    }

    public function down(): void {}
};
