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
            $table->string('path');
            $table->json('variants')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime', 60);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->json('alt')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Pages are built from ordered sections; content is bilingual (CMS-01, CMS-02).
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('slug_ro', 160)->unique();
            $table->string('slug_hu', 160)->unique();
            // home | standard | legal
            $table->string('template', 20)->default('standard');
            $table->json('draft');
            $table->json('published')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('page_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('content');
            $table->string('note', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['page_id', 'number']);
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            // header | footer
            $table->string('menu', 20);
            $table->json('label');
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();
            // For non-page links (e.g. booking): a route key or an absolute URL.
            $table->string('target', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['menu', 'sort_order']);
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            // offer | article
            $table->string('type', 20);
            $table->string('slug_ro', 160);
            $table->string('slug_hu', 160);
            $table->json('draft');
            $table->json('published')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('image_id')->nullable()->constrained('media')->nullOnDelete();
            // Offers are visible only within their period (CMS-02).
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['type', 'slug_ro']);
            $table->unique(['type', 'slug_hu']);
        });

        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path', 255)->unique();
            $table->string('to_url', 500)->nullable();
            // 301 | 302 | 410
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();
        });

        Schema::create('not_found_logs', function (Blueprint $table) {
            $table->id();
            $table->string('path', 255)->unique();
            $table->unsignedInteger('hits')->default(0);
            $table->string('last_referer', 255)->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });

        // Requests from the site's forms (CRM-01). Managed in the CRM.
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            // contact | product_inquiry | booking_request | b2b
            $table->string('type', 30);
            // new | contacted | in_progress | converted | closed
            $table->string('status', 20)->default('new');
            $table->string('name', 200);
            $table->string('phone', 32)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('company', 200)->nullable();
            $table->text('message')->nullable();
            $table->string('locale', 5)->default('ro');
            $table->string('source', 255)->nullable();
            $table->json('utm')->nullable();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('due_at')->nullable();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::table('locations', fn (Blueprint $t) => $t->foreignId('image_id')->nullable()->after('opening_hours')->constrained('media')->nullOnDelete());
        Schema::table('services', fn (Blueprint $t) => $t->foreignId('image_id')->nullable()->after('color')->constrained('media')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('services', fn (Blueprint $t) => $t->dropConstrainedForeignId('image_id'));
        Schema::table('locations', fn (Blueprint $t) => $t->dropConstrainedForeignId('image_id'));
        foreach (['leads', 'not_found_logs', 'redirects', 'posts', 'menu_items', 'page_versions', 'pages', 'media'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
