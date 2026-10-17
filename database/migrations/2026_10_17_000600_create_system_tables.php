<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 120)->primary();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60);
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            // Never passwords, tokens or clinical content (SEC-03).
            $table->json('meta')->nullable();
            $table->dateTime('created_at');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['action', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // Customer translation corrections; they survive ZIP updates (TRD-04).
        Schema::create('translation_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 5);
            $table->string('key', 190);
            $table->text('draft_value')->nullable();
            $table->text('published_value')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['locale', 'key']);
        });

        Schema::create('translation_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 5);
            $table->string('key', 190);
            $table->text('value')->nullable();
            // draft | publish | restore | import | reset
            $table->string('action', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('created_at');
            $table->index(['locale', 'key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_revisions');
        Schema::dropIfExists('translation_overrides');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('settings');
    }
};
