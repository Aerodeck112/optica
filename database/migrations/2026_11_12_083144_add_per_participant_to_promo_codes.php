<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coduri „pentru fiecare participant”: o singură persoană rezervă pentru tot grupul și fiecare participant primește
 * reducerea; la locație, fiecare o confirmă semnând declarația, iar recepția vede cine a mai folosit oferta.
 * WELCOME13 trece pe acest mod. Textele blocului de pe prima pagină se actualizează doar dacă nu au fost modificate.
 */
return new class extends Migration
{
    private const OLD_TERMS = [
        'ro' => 'Codul acoperă sesiunea unei persoane la „Sesiune VR — 60 de minute”, pentru sesiunile din 13–15 noiembrie 2026; ceilalți participanți plătesc tariful obișnuit. Se folosește o singură dată pentru fiecare e-mail sau telefon.',
        'en' => 'The code covers one person on the “VR session — 60 minutes”, for sessions on 13–15 November 2026; the rest of the group pays the regular price. It can be used once per e-mail address or phone number.',
    ];

    private const NEW_TERMS = [
        'ro' => 'Rezervi o dată pentru tot grupul: fiecare participant primește sesiunea gratuită la „Sesiune VR — 60 de minute”, pentru sesiunile din 13–15 noiembrie 2026. Oferta e valabilă o singură dată pentru fiecare persoană; la sosire, fiecare participant o confirmă semnând declarația de participare.',
        'en' => 'Book once for the whole group: every participant gets the free “VR session — 60 minutes”, for sessions on 13–15 November 2026. The offer is valid once per person; on arrival, each participant confirms it by signing the participation agreement.',
    ];

    public function up(): void
    {
        Schema::table('promo_codes', function (Blueprint $table) {
            $table->boolean('per_participant')->default(false)->after('discount_value');
        });
        DB::table('promo_codes')->where('code', 'WELCOME13')->update(['per_participant' => true, 'updated_at' => now()]);

        $home = DB::table('pages')->where('key', 'home')->first();
        if (! $home) {
            return;
        }
        $changes = [];
        foreach (['blocks', 'draft_blocks'] as $column) {
            $blocks = json_decode((string) $home->{$column}, true);
            if (! is_array($blocks)) {
                continue;
            }
            $changed = false;
            foreach (self::OLD_TERMS as $locale => $old) {
                foreach ($blocks[$locale] ?? [] as $index => $block) {
                    if (($block['type'] ?? null) === 'promo_code' && ($block['data']['terms'] ?? null) === $old) {
                        $blocks[$locale][$index]['data']['terms'] = self::NEW_TERMS[$locale];
                        $changed = true;
                    }
                }
            }
            if ($changed) {
                $changes[$column] = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        if ($changes) {
            DB::table('pages')->where('id', $home->id)->update($changes + ['updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('promo_codes', function (Blueprint $table) {
            $table->dropColumn('per_participant');
        });
    }
};
