<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Working schedule of a specialist in a location (LOC-02). Times are local wall-clock times.
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('specialist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            // 1 = same week repeated; 2+ = alternating weeks. Week 1 starts on cycle_anchor_date's week.
            $table->unsignedTinyInteger('cycle_weeks')->default(1);
            $table->date('cycle_anchor_date');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['specialist_id', 'valid_from', 'valid_to']);
            $table->index(['location_id', 'valid_from']);
        });

        Schema::create('schedule_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('cycle_week')->default(1);
            // ISO weekday: 1 = Monday … 7 = Sunday.
            $table->unsignedTinyInteger('weekday');
            // morning | before_noon | afternoon | evening | extra_1 | extra_2
            $table->string('segment', 20);
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('slot_minutes')->nullable();
            $table->boolean('online_enabled')->default(false);
            // Allowed service IDs; null = every active offering of the specialist in the location.
            $table->json('service_ids')->nullable();
            $table->foreignId('resource_id')->nullable()->constrained()->nullOnDelete();
            $table->string('color', 9)->nullable();
            $table->json('label')->nullable();
            $table->timestamps();
            $table->index(['schedule_id', 'cycle_week', 'weekday']);
        });

        // Leaves, holidays, closures and extra availability (LOC-02). Null scope = all.
        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('specialist_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->cascadeOnDelete();
            // unavailable | available
            $table->string('kind', 20)->default('unavailable');
            // leave | holiday | training | closure | other
            $table->string('reason', 20)->default('leave');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->boolean('online_enabled')->default(false);
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['starts_on', 'ends_on']);
            $table->index(['specialist_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_exceptions');
        Schema::dropIfExists('schedule_segments');
        Schema::dropIfExists('schedules');
    }
};
