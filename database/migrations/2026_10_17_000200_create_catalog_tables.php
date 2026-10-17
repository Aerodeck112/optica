<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('code', 40)->unique();
            // ophthalmologist | optometrist | other — stable technical key (LNG-03).
            $table->string('type', 30);
            $table->string('display_name', 160);
            $table->json('content');
            $table->json('published_content')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('photo_path')->nullable();
            $table->json('languages')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('external_ref', 120)->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('category', 40)->nullable()->index();
            $table->json('content');
            $table->json('published_content')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedSmallInteger('default_duration')->default(15);
            $table->unsignedSmallInteger('buffer_before')->default(0);
            $table->unsignedSmallInteger('buffer_after')->default(0);
            $table->decimal('default_price', 10, 2)->nullable();
            $table->string('color', 9)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('external_ref', 120)->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        // Specialist activity in a location, incl. the per-location online settings seen in Evolvo.
        Schema::create('location_specialist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('specialist_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('online_enabled')->default(false);
            $table->boolean('online_paused')->default(false);
            // alias, public info and pause message, per language.
            $table->json('content')->nullable();
            $table->unsignedInteger('min_lead_minutes')->default(0);
            $table->unsignedSmallInteger('max_advance_days')->default(90);
            $table->boolean('notify_on_online_booking')->default(false);
            $table->timestamps();
            $table->unique(['location_id', 'specialist_id']);
        });

        // Bookable combination location–specialist–service (LOC-03).
        Schema::create('offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('specialist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->unsignedSmallInteger('buffer_before')->nullable();
            $table->unsignedSmallInteger('buffer_after')->nullable();
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->string('resource_type', 40)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->boolean('online_enabled')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['location_id', 'specialist_id', 'service_id']);
            $table->index(['service_id', 'online_enabled']);
        });

        Schema::create('location_travel_times', function (Blueprint $table) {
            $table->foreignId('from_location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('to_location_id')->constrained('locations')->cascadeOnDelete();
            $table->unsignedSmallInteger('minutes');
            $table->primary(['from_location_id', 'to_location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_travel_times');
        Schema::dropIfExists('offerings');
        Schema::dropIfExists('location_specialist');
        Schema::dropIfExists('services');
        Schema::dropIfExists('specialists');
    }
};
