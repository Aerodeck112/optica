<?php

use App\Modules\Marketing\Models\SocialPost;
use Illuminate\Database\Migrations\Migration;

/**
 * Textele postărilor din plan despre aniversări, companii și vouchere, cu pachetele și prețurile confirmate.
 * Se actualizează doar postările din plan care nu au fost încă publicate.
 */
return new class extends Migration
{
    private const TITLES = ['Aniversări într-o altă lume', 'Team building', 'Petrecerea de firmă', 'Vouchere cadou', 'Aniversări'];

    public function up(): void
    {
        $plan = json_decode((string) file_get_contents(resource_path('data/social-plan-2026-11.json')), true);
        foreach ((array) ($plan['posts'] ?? []) as $post) {
            if (! in_array($post['title'], self::TITLES, true)) {
                continue;
            }
            SocialPost::query()->whereDate('publish_on', $post['date'])->where('title', $post['title'])
                ->where('source', 'plan')->whereIn('status', ['planned', 'ready'])
                ->update(['caption' => $post['caption'], 'notes' => $post['notes']]);
        }
    }

    public function down(): void {}
};
