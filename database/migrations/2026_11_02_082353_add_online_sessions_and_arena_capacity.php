<?php

use App\Modules\Booking\Models\Resource;
use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\Game;
use App\Modules\Catalog\Models\Package;
use App\Modules\Cms\Models\Media;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sesiunile obișnuite, confirmate de proprietar (02.11.2026), la prețurile voucherelor:
 *  - Sesiune VR: 110 lei / persoană, 60 de minute, în arena comună (până la 8 jucători simultan);
 *  - Arena privată: 880 lei, până la 8 persoane, 60 de minute, arena doar pentru grup.
 * Ambele se rezervă online, cu ora confirmată imediat. Capacitatea arenei: 8 jucători simultan (documentele ofertei).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('pages')->where('key', 'home')->exists()) {
            return;
        }

        $arena = Resource::query()->where('type', 'arena')->whereNull('parent_id')->first();
        if ($arena && $arena->capacity === null) {
            $arena->forceFill(['capacity' => 8])->save();
        }

        $definitions = [
            'sesiune-vr' => [
                'name' => 'Sesiune VR — 60 de minute', 'type' => 'session', 'session_mode' => 'shared', 'pricing_model' => 'per_person', 'price' => 11000,
                'min' => 1, 'sort' => 1, 'featured' => true, 'cover' => 'social-doi-prieteni-in-joc',
                'summary' => 'Intri în joc cu prietenii tăi; în arenă pot juca până la 8 persoane simultan.',
                'included' => ['60 de minute de VR multiplayer', 'Instructaj și echipament', 'Acces la zona de PlayStation'],
            ],
            'arena-privata' => [
                'name' => 'Arena privată — 60 de minute', 'type' => 'private', 'session_mode' => 'private', 'pricing_model' => 'flat', 'price' => 88000,
                'min' => null, 'sort' => 2, 'featured' => false, 'cover' => 'social-echipa-in-linie',
                'summary' => 'Arena VR doar pentru grupul vostru, până la 8 persoane.',
                'included' => ['60 de minute de VR multiplayer', 'Arena VR doar pentru grupul vostru', 'Acces la cele 2 stații PlayStation', 'Instructaj și echipament'],
            ],
        ];

        $extra = Addon::query()->whereIn('name->ro', ['Timp VR suplimentar — 30 de minute', 'Timp VR suplimentar — 60 de minute'])->pluck('id')->all();
        foreach ($definitions as $slug => $d) {
            if (Package::query()->where('slug->ro', $slug)->exists()) {
                continue;
            }
            $package = Package::query()->create([
                'slug' => ['ro' => $slug],
                'name' => ['ro' => $d['name']],
                'type' => $d['type'],
                'summary' => ['ro' => $d['summary']],
                'included' => ['ro' => array_map(fn ($text) => ['text' => $text], $d['included'])],
                'session_mode' => $d['session_mode'],
                'min_participants' => $d['min'],
                'max_participants' => 8,
                'slot_minutes' => 60,
                'play_minutes' => 60,
                'pricing_model' => $d['pricing_model'],
                'price_minor' => $d['price'],
                'currency' => 'RON',
                'deposit_type' => 'none',
                'booking_mode' => 'instant',
                'resource_ids' => $arena ? [$arena->id] : null,
                'cover_id' => Media::query()->where('static_key', $d['cover'])->value('id'),
                'published_locales' => ['ro'],
                'is_active' => true,
                'is_featured' => $d['featured'],
                'use_in_ai' => true,
                'sort' => $d['sort'],
            ]);
            $package->addons()->syncWithoutDetaching($extra);
            $package->games()->syncWithoutDetaching(Game::query()->whereIn('cluj_status', ['available'])->pluck('id')->all());
        }
    }

    public function down(): void {}
};
