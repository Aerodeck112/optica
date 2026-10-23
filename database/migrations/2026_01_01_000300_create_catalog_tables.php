<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cotele TVA au perioade de valabilitate; documentele păstrează snapshot-ul cotei.
        Schema::create('vat_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->decimal('rate', 5, 2);                 // ex: 21.00
            $table->string('category_code', 4)->default('S'); // UNCL5305: S, Z, E, AE, K, G, O
            $table->string('exemption_reason', 255)->nullable();
            $table->string('exemption_code', 40)->nullable(); // ex: VATEX-EU-...
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('group', 40)->default('standard'); // standard | reduced | exempt — grupul logic folosit de produse
            $table->text('notes')->nullable();
            $table->boolean('needs_accountant_review')->default(true);
            $table->timestamps();
            $table->index(['group', 'valid_from']);
        });

        Schema::create('game_genres', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->string('slug', 80)->unique();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->json('slug');
            $table->json('title');
            $table->json('tagline')->nullable();
            $table->json('description')->nullable();
            $table->json('rules')->nullable();             // reguli relevante (markdown)
            $table->foreignId('genre_id')->nullable()->constrained('game_genres')->nullOnDelete();
            $table->string('audience', 20)->default('all'); // kids | teens_adults | all
            // Valori confirmate pentru Cluj. Null = neconfirmat → nu se afișează public.
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->unsignedTinyInteger('min_players')->nullable();
            $table->unsignedTinyInteger('max_players')->nullable();
            $table->unsignedSmallInteger('play_minutes')->nullable(); // durata efectivă a jocului
            $table->unsignedTinyInteger('intensity')->nullable();     // 1 calm … 3 intens
            $table->json('reference_data')->nullable();    // valori publicate de franciză (doar referință internă)
            $table->string('cluj_status', 20)->default('unconfirmed'); // available | coming_soon | unconfirmed | retired
            $table->foreignId('cover_id')->nullable()->constrained('media')->nullOnDelete();
            $table->json('gallery_ids')->nullable();
            $table->foreignId('trailer_id')->nullable()->constrained('media')->nullOnDelete();
            $table->json('seo_title')->nullable();
            $table->json('seo_description')->nullable();
            $table->json('published_locales')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('use_in_ai')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['cluj_status', 'sort']);
        });

        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->json('slug');
            $table->json('name');
            $table->string('type', 20);                    // session | birthday | event | private
            $table->json('summary')->nullable();
            $table->json('description')->nullable();
            $table->json('included')->nullable();          // listă servicii incluse (confirmate)
            $table->json('conditions')->nullable();
            $table->string('session_mode', 10)->default('private'); // private | shared
            $table->unsignedSmallInteger('min_participants')->nullable();
            $table->unsignedSmallInteger('max_participants')->nullable();
            $table->unsignedSmallInteger('slot_minutes')->nullable();     // durata totală rezervată (instructaj + joc + pregătire)
            $table->unsignedSmallInteger('play_minutes')->nullable();     // timpul efectiv de joc
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);
            $table->string('pricing_model', 20)->default('per_person'); // per_person | flat | flat_plus_extra
            $table->unsignedBigInteger('price_minor')->nullable();        // null = preț neconfigurat → doar cerere
            $table->unsignedSmallInteger('included_participants')->nullable(); // pentru flat_plus_extra
            $table->unsignedBigInteger('extra_person_price_minor')->nullable();
            $table->string('currency', 3)->default('RON');
            $table->string('vat_group', 40)->default('standard');
            $table->string('deposit_type', 10)->default('none'); // none | fixed | percent
            $table->unsignedBigInteger('deposit_value')->nullable(); // bani sau procent * 100
            $table->string('booking_mode', 10)->default('request'); // instant | request
            $table->unsignedSmallInteger('min_lead_minutes')->nullable(); // suprascrie setarea globală
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->json('resource_ids')->nullable();      // resursele eligibile (în ordinea preferinței)
            $table->boolean('requires_adult_contact')->default(true);
            $table->foreignId('cover_id')->nullable()->constrained('media')->nullOnDelete();
            $table->json('seo_title')->nullable();
            $table->json('seo_description')->nullable();
            $table->json('published_locales')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->boolean('use_in_ai')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['type', 'is_active']);
        });

        Schema::create('package_game', function (Blueprint $table) {
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->primary(['package_id', 'game_id']);
        });

        Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('pricing_model', 20)->default('flat'); // flat | per_person
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->string('vat_group', 40)->default('standard');
            $table->unsignedSmallInteger('extra_minutes')->default(0); // prelungește slotul
            $table->unsignedSmallInteger('max_quantity')->default(1);
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('addon_package', function (Blueprint $table) {
            $table->foreignId('addon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->primary(['addon_id', 'package_id']);
        });

        // Reguli de preț pe zile/intervale (ex: weekend, după-amiază). Prima regulă potrivită câștigă (prioritate).
        Schema::create('price_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->string('label', 120);
            $table->json('weekdays')->nullable();          // [6,7]
            $table->time('time_from')->nullable();
            $table->time('time_until')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->unsignedBigInteger('extra_person_price_minor')->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['price_rules', 'addon_package', 'addons', 'package_game', 'packages', 'games', 'game_genres', 'vat_rates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
