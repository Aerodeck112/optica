<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 30)->default('public');
            $table->string('directory', 190);
            $table->string('filename', 190);               // numele fișierului original stocat
            $table->string('original_name', 255)->nullable();
            $table->string('mime', 100);
            $table->string('kind', 30);                    // image | video | document
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->decimal('focal_x', 4, 3)->default(0.5);
            $table->decimal('focal_y', 4, 3)->default(0.5);
            $table->string('dominant_color', 7)->nullable();
            $table->json('alt')->nullable();               // traductibil
            $table->json('caption')->nullable();
            $table->string('credit', 190)->nullable();
            // proveniență și drepturi
            $table->string('category', 30)->default('other'); // network_photo | local_photo | game_art | brand_art | document | other
            $table->string('source', 255)->nullable();
            $table->string('rights_status', 30)->default('unknown'); // owned | licensed_franchise | licensed_other | unknown
            $table->text('rights_notes')->nullable();
            $table->boolean('depicts_minors')->default(false);
            $table->json('variants')->nullable();          // [{format,width,height,path}]
            $table->foreignId('poster_id')->nullable();    // poster pentru video
            $table->string('static_key', 120)->nullable()->unique(); // asset generat la build (public/media/static)
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['kind', 'category']);
        });

        Schema::create('media_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->morphs('usable');
            $table->string('field', 120);
            $table->timestamps();
            $table->unique(['media_id', 'usable_type', 'usable_id', 'field'], 'media_usage_unique');
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();           // identificator intern: home, birthdays, legal.terms...
            $table->string('template', 40)->default('blocks'); // blocks | legal | system
            $table->json('slug');                          // {ro: "aniversari", en: "birthdays"}
            $table->json('title');
            $table->json('seo_title')->nullable();
            $table->json('seo_description')->nullable();
            $table->foreignId('og_image_id')->nullable()->constrained('media')->nullOnDelete();
            $table->json('blocks')->nullable();            // versiunea publicată: {ro: [...], en: [...]}
            $table->json('draft_blocks')->nullable();      // ciorna curentă
            $table->json('draft_meta')->nullable();
            $table->boolean('has_draft')->default(false);
            $table->json('published_locales')->nullable(); // ["ro"]
            $table->string('status', 20)->default('draft'); // draft | scheduled | published | archived
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->boolean('noindex')->default(false);
            $table->boolean('is_system')->default(false);  // paginile de bază nu pot fi șterse
            $table->unsignedInteger('sort')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('page_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->json('snapshot');                      // titlu, slug, SEO, blocuri
            $table->string('reason', 40);                  // draft_saved | published | restored | scheduled
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['page_id', 'created_at']);
        });

        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->string('location', 40)->unique();      // header | footer_explore | footer_info | legal
            $table->string('name', 120);
            $table->timestamps();
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('menu_items')->cascadeOnDelete();
            $table->json('label');
            $table->string('link_type', 20)->default('page'); // page | route | url
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('route', 80)->nullable();
            $table->string('url', 500)->nullable();
            $table->boolean('open_new_tab')->default(false);
            $table->boolean('is_visible')->default(true);
            $table->string('style', 20)->default('link');  // link | button
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('category', 40)->default('general'); // general | booking | birthdays | safety | vouchers | corporate
            $table->json('question');
            $table->json('answer');
            $table->json('published_locales')->nullable();
            $table->boolean('is_published')->default(false);
            $table->boolean('use_in_ai')->default(true);   // aprobat ca bază de cunoștințe pentru asistent
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->json('slug');
            $table->json('title');
            $table->json('excerpt')->nullable();
            $table->json('body')->nullable();              // blocuri controlate
            $table->foreignId('cover_id')->nullable()->constrained('media')->nullOnDelete();
            $table->json('seo_title')->nullable();
            $table->json('seo_description')->nullable();
            $table->json('published_locales')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'published_at']);
        });

        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('placement', 30)->default('top'); // top | home_hero | booking
            $table->json('text');
            $table->json('link_label')->nullable();
            $table->string('link_url', 500)->nullable();
            $table->string('tone', 20)->default('info');   // info | promo | warning
            $table->boolean('is_dismissible')->default(true);
            $table->boolean('is_active')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('published_locales')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);                    // terms | privacy | cookies | booking_policy | voucher_terms | arena_rules | minors | safety | media_consent
            $table->string('version', 20);
            $table->json('title');
            $table->json('body');                          // markdown
            $table->json('slug');
            $table->string('status', 20)->default('draft'); // draft | published | superseded
            $table->timestamp('effective_at')->nullable();
            $table->json('review_flags')->nullable();      // elemente de validat de jurist/contabil/operator
            $table->string('content_hash', 64)->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['type', 'version']);
        });

        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path', 500);
            $table->string('to_url', 500)->nullable();
            $table->unsignedSmallInteger('status_code')->default(301); // 301 | 302 | 410
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique('from_path');
        });

        Schema::create('gallery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->string('album', 40)->default('general'); // general | birthdays | friends | corporate | games
            $table->json('caption')->nullable();
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // Recenzii reale: se introduc doar cu sursă verificabilă (Google, Tripadvisor, formular propriu cu acord).
        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->string('author_name', 120);
            $table->text('body');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('source', 40);                  // google | tripadvisor | facebook | own_form
            $table->string('source_url', 500)->nullable();
            $table->date('reviewed_on')->nullable();
            $table->string('locale', 5)->default('ro');
            $table->boolean('is_published')->default(false);
            $table->boolean('publication_consent')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['testimonials', 'gallery_items', 'redirects', 'legal_documents', 'banners', 'posts', 'faqs', 'menu_items', 'menus', 'page_revisions', 'pages', 'media_usages', 'media'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
