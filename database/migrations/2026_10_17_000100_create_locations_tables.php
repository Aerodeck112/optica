<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            // Bilingual public content (draft) and the last published snapshot.
            $table->json('content');
            $table->json('published_content')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('city', 120);
            $table->string('address', 255);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 190)->nullable();
            $table->foreignId('manager_user_id')->nullable();
            // Store opening hours (shop), distinct from consultation schedules (LOC-01).
            $table->json('opening_hours')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->boolean('online_booking_enabled')->default(false);
            $table->string('confirmation_mode', 20)->default('reception');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('external_ref', 120)->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->json('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['location_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resources');
        Schema::dropIfExists('locations');
    }
};
