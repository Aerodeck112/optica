<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Saved filter sets (RAP-02).
        Schema::create('saved_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('filters');
            $table->timestamps();
        });

        // Exports run in the background and stay private to their author (RAP-05).
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('report', 30);
            $table->string('format', 5);
            $table->string('locale', 5);
            $table->json('filters');
            // queued | running | done | failed
            $table->string('status', 10)->default('queued');
            $table->string('path', 255)->nullable();
            $table->unsignedInteger('rows')->nullable();
            $table->string('error', 300)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        // Daily / weekly / monthly reports to explicitly configured recipients (RAP-05).
        Schema::create('report_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('report', 30);
            $table->string('format', 5);
            $table->string('locale', 5);
            // daily | weekly | monthly
            $table->string('frequency', 10);
            $table->json('filters');
            $table->json('recipients');
            $table->boolean('is_active')->default(true);
            // Sent with the rights and locations of the person who created it.
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_sent_at')->nullable();
            $table->string('last_result', 300)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_subscriptions');
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('saved_reports');
    }
};
