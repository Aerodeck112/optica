<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SocialMediaPackSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social media: planul de postări (o postare = o zi + canale + text + imagini din biblioteca media),
 * etichete pe imagini pentru alegerea automată și pachetul de fotografii pentru postări.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('rights_notes');
        });

        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->date('publish_on');
            $table->string('publish_time', 5)->nullable();
            $table->json('channels');                       // instagram | facebook | story | tiktok
            $table->string('format', 20)->default('post');  // post | carousel | story | reel
            $table->string('theme', 40);
            $table->string('title', 160);
            $table->text('caption');
            $table->string('hashtags', 500)->nullable();
            $table->json('media_ids')->nullable();
            $table->string('status', 20)->default('planned'); // planned | ready | published | skipped
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('published_url', 500)->nullable();
            $table->text('notes')->nullable();
            $table->string('source', 20)->default('manual'); // manual | plan | generator | ai
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['publish_on', 'status']);
        });

        (new RolesAndPermissionsSeeder)->run();
        (new SocialMediaPackSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }
};
