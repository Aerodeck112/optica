<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coduri de reducere pentru rezervările online și oferta de deschidere confirmată de proprietar (07.11.2026):
 * codul WELCOME13 oferă o sesiune VR de 60 de minute gratuită (o persoană per rezervare) pentru sesiunile din 13–15 noiembrie,
 * afișat mare pe prima pagină și într-un banner. Tot aici: vârstele minime ale jocurilor, confirmate de proprietar
 * (Tavern 6+, Starbase 12+, Shmooter 6+, Ghost Tower 6+, Safe Night 12+, Kernel 12+); restul, setate după tipul jocului.
 */
return new class extends Migration
{
    public const CODE = 'WELCOME13';

    /** Vârsta minimă pe serii de jocuri (prefixul slug-ului românesc). */
    public const MIN_AGES = [
        'taverna' => 6, 'starbase-' => 12, 'shmooter-' => 6, 'ghost-tower-' => 6, 'safe-night' => 12, 'kernel-' => 12,
        'mysterious-tower-' => 6, 'colony-' => 12,
    ];

    public const BLOCK = ['type' => 'promo_code', 'data' => [
        'code' => self::CODE,
        'title' => 'Prima ta sesiune VR, gratuită.',
        'body' => 'Oferta de deschidere: o sesiune de 60 de minute gratuită în weekendul 13–15 noiembrie. Introdu codul când rezervi.',
        'terms' => 'Codul acoperă sesiunea unei persoane la „Sesiune VR — 60 de minute”, pentru sesiunile din 13–15 noiembrie 2026; ceilalți participanți plătesc tariful obișnuit. Se folosește o singură dată pentru fiecare e-mail sau telefon.',
        'primary_label' => 'Rezervă acum', 'primary_link' => 'route:booking?pachet=sesiune-vr&cod='.self::CODE,
    ]];

    public const BLOCK_EN = ['type' => 'promo_code', 'data' => [
        'code' => self::CODE,
        'title' => 'Your first VR session is on us.',
        'body' => 'Opening offer: one free 60-minute session over the opening weekend, 13–15 November. Enter the code when you book.',
        'terms' => 'The code covers one person on the “VR session — 60 minutes”, for sessions on 13–15 November 2026; the rest of the group pays the regular price. It can be used once per e-mail address or phone number.',
        'primary_label' => 'Book now', 'primary_link' => 'route:booking?pachet=vr-session&cod='.self::CODE,
    ]];

    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('discount_type', 20); // free_participants | percent | fixed
            $table->unsignedInteger('discount_value');
            $table->json('package_ids')->nullable();
            $table->date('redeem_from')->nullable();
            $table->date('redeem_until')->nullable();
            $table->date('session_from')->nullable();
            $table->date('session_until')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedSmallInteger('max_per_customer')->nullable()->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('promo_code_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_code_id')->constrained('promo_codes')->restrictOnDelete();
            $table->foreignId('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->unsignedBigInteger('discount_minor');
            $table->timestamps();
            $table->index(['promo_code_id', 'customer_id']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('promo_code_id')->nullable()->after('discount_minor')->constrained('promo_codes')->nullOnDelete();
        });

        $this->gameAges();

        // oferta și blocul: doar pe un site deja instalat
        if (! DB::table('pages')->where('key', 'home')->exists()) {
            return;
        }

        $session = DB::table('packages')->where('slug->ro', 'sesiune-vr')->where('is_active', true)->value('id');
        DB::table('promo_codes')->insert([
            'code' => self::CODE,
            'name' => 'Oferta de deschidere — sesiune VR gratuită',
            'discount_type' => 'free_participants',
            'discount_value' => 1,
            'package_ids' => $session ? json_encode([$session]) : null,
            'redeem_until' => '2026-11-15',
            'session_from' => '2026-11-13',
            'session_until' => '2026-11-15',
            'max_per_customer' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->homeBlock();

        DB::table('banners')->where('name', 'Deschidere oficială 13.11')->update(['is_active' => false, 'updated_at' => now()]);
        if (! DB::table('banners')->where('name', 'Cod WELCOME13')->exists()) {
            DB::table('banners')->insert([
                'name' => 'Cod WELCOME13',
                'placement' => 'top',
                'text' => json_encode([
                    'ro' => 'Deschidem vineri, 13 noiembrie. Cu codul WELCOME13, sesiunea VR de 60 de minute e gratuită (13–15 noiembrie).',
                    'en' => 'We open on Friday, 13 November. With code WELCOME13, your 60-minute VR session is free (13–15 November).',
                ], JSON_UNESCAPED_UNICODE),
                'link_label' => json_encode(['ro' => 'Rezervă acum', 'en' => 'Book now'], JSON_UNESCAPED_UNICODE),
                'link_url' => 'route:booking?pachet=sesiune-vr&cod='.self::CODE,
                'tone' => 'promo',
                'is_dismissible' => true,
                'is_active' => true,
                'starts_at' => null,
                // sfârșitul zilei de 15 noiembrie, ora României (UTC+2)
                'ends_at' => '2026-11-15 21:59:59',
                'published_locales' => json_encode(['ro', 'en']),
                'sort' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Cache::forget('aw.settings.v1');
    }

    private function gameAges(): void
    {
        foreach (DB::table('games')->get(['id', 'slug']) as $game) {
            $slug = (string) (json_decode((string) $game->slug, true)['ro'] ?? '');
            foreach (self::MIN_AGES as $prefix => $age) {
                if ($slug === $prefix || (str_ends_with($prefix, '-') && str_starts_with($slug, $prefix))) {
                    DB::table('games')->where('id', $game->id)->update(['min_age' => $age, 'updated_at' => now()]);
                    break;
                }
            }
        }
    }

    /** Blocul cu codul, imediat după numărătoarea inversă (sau după hero), în română și în engleză. */
    private function homeBlock(): void
    {
        $home = DB::table('pages')->where('key', 'home')->first();
        $changes = [];
        foreach (['blocks', 'draft_blocks'] as $column) {
            $blocks = json_decode((string) $home->{$column}, true);
            if (! is_array($blocks)) {
                continue;
            }
            $changed = false;
            foreach (['ro' => self::BLOCK, 'en' => self::BLOCK_EN] as $locale => $block) {
                if (! isset($blocks[$locale]) || ! is_array($blocks[$locale]) || collect($blocks[$locale])->contains(fn ($b) => ($b['type'] ?? null) === 'promo_code')) {
                    continue;
                }
                $position = 0;
                foreach (array_values($blocks[$locale]) as $index => $existing) {
                    if (in_array($existing['type'] ?? null, ['hero_portal', 'opening_countdown'], true)) {
                        $position = $index + 1;
                    }
                }
                $list = array_values($blocks[$locale]);
                array_splice($list, $position, 0, [$block]);
                $blocks[$locale] = $list;
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

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promo_code_id');
        });
        Schema::dropIfExists('promo_code_redemptions');
        Schema::dropIfExists('promo_codes');
    }
};
