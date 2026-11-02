<?php

use App\Modules\Cms\Models\Media;
use App\Modules\Marketing\Models\SocialPost;
use Illuminate\Database\Migrations\Migration;

/**
 * Planul de postări pentru 2–29 noiembrie 2026 (campania de deschidere + primele săptămâni),
 * din resources/data/social-plan-2026-11.json. Postările existente (aceeași zi și același titlu) nu se dublează.
 */
return new class extends Migration
{
    public function up(): void
    {
        $plan = json_decode((string) file_get_contents(resource_path('data/social-plan-2026-11.json')), true);
        $keys = collect((array) ($plan['posts'] ?? []))->pluck('media')->flatten()->unique()->all();
        $media = Media::query()->whereIn('static_key', $keys)->pluck('id', 'static_key');

        foreach ((array) ($plan['posts'] ?? []) as $post) {
            if (SocialPost::query()->whereDate('publish_on', $post['date'])->where('title', $post['title'])->exists()) {
                continue;
            }
            SocialPost::query()->create([
                'publish_on' => $post['date'],
                'publish_time' => $post['time'],
                'channels' => $post['channels'],
                'format' => $post['format'],
                'theme' => $post['theme'],
                'title' => $post['title'],
                'caption' => $post['caption'],
                'hashtags' => $post['hashtags'] ?: null,
                'media_ids' => collect($post['media'])->map(fn ($key) => $media[$key] ?? null)->filter()->values()->all(),
                'notes' => $post['notes'],
                'status' => 'planned',
                'source' => 'plan',
            ]);
        }
    }

    public function down(): void {}
};
