<?php

namespace Tests\Support;

use App\Domain\Cms\Sections;
use App\Models\Page;

/** Fictitious CMS content for tests. */
trait CmsFixtures
{
    protected function makePage(string $key, string $slugRo, string $slugHu, array $ro, array $hu, bool $publish = true, string $template = 'standard'): Page
    {
        $section = Sections::make('rich_text');
        $section['text']['ro'] = ['heading' => $ro['heading'] ?? '', 'body' => $ro['body'] ?? ''];
        $section['text']['hu'] = ['heading' => $hu['heading'] ?? '', 'body' => $hu['body'] ?? ''];
        $content = Sections::emptyPage($ro['title'] ?? $key, $hu['title'] ?? $key);
        $content['sections'] = [$section];

        return Page::create([
            'key' => $key,
            'slug_ro' => $slugRo,
            'slug_hu' => $slugHu,
            'template' => $template,
            'draft' => $content,
            'published' => $publish ? $content : null,
            'published_at' => $publish ? now() : null,
        ]);
    }

    /** A role holding exactly the given permissions. */
    protected function staffWith(array $permissions): \App\Models\User
    {
        $user = $this->staff('reception');
        $role = \App\Models\Role::create(['key' => 'test-'.uniqid(), 'name' => ['ro' => 'Test', 'hu' => 'Test']]);
        $role->permissions()->sync(\App\Models\Permission::whereIn('key', $permissions)->pluck('id'));
        $user->roles()->sync([$role->id]);
        app(\App\Support\Access\AccessControl::class)->flush();

        return $user->fresh();
    }
}
