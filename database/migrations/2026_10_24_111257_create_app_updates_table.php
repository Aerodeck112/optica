<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_updates', function (Blueprint $table) {
            $table->id();
            $table->string('version', 40);
            $table->string('previous_version', 40)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('status', 20); // applied | failed | rolled_back
            $table->unsignedInteger('files_count')->default(0);
            $table->string('package_sha256', 64);
            $table->string('backup_dir', 255)->nullable();   // copia fișierelor înlocuite (pentru revenire)
            $table->string('database_backup', 255)->nullable();
            $table->json('log')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_updates');
    }
};
