<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);                    // database | files | full
            $table->string('status', 20);                  // running | succeeded | failed | verified
            $table->string('path', 255)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->string('remote_status', 20)->nullable(); // skipped | uploaded | failed
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // Cereri ale persoanelor vizate (acces/export, ștergere/anonimizare), cu termenul legal urmărit.
        Schema::create('data_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);                    // export | erasure | rectification | objection
            $table->string('status', 20)->default('received'); // received | verified | completed | rejected
            $table->string('channel', 20)->default('account');
            $table->text('details')->nullable();
            $table->text('outcome')->nullable();           // ce s-a șters/anonimizat și ce s-a păstrat (și de ce)
            $table->string('export_path', 255)->nullable();
            $table->date('due_on')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_requests');
        Schema::dropIfExists('backup_runs');
    }
};
