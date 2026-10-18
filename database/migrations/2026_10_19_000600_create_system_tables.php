<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // History of update attempts; the journal on disk stays the source of truth during a run (UPD-01, UPD-06).
        Schema::create('update_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('from_version', 30);
            $table->string('to_version', 30)->nullable();
            $table->string('package_name', 190)->nullable();
            // rejected | verified | running | done | failed | rolled_back | cancelled
            $table->string('status', 20);
            $table->string('step', 20)->nullable();
            $table->json('log')->nullable();
            $table->text('errors')->nullable();
            $table->string('backup_name', 120)->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // Imports from CSV/XLSX exports (MIG-01…04): mapping, simulation, resumable batches, report.
        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // patients | appointments
            $table->string('target', 20);
            $table->string('source_label', 60)->default('evolvo');
            $table->string('original_name', 190);
            $table->string('file_path', 255);
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();
            // uploaded | simulated | running | done | failed
            $table->string('status', 20)->default('uploaded');
            $table->unsignedInteger('total_rows')->default(0);
            // Next data row to process (0-based); a run resumes here after an interruption.
            $table->unsignedInteger('cursor')->default(0);
            $table->json('simulation')->nullable();
            $table->json('stats')->nullable();
            $table->longText('issues')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
        Schema::dropIfExists('update_runs');
    }
};
