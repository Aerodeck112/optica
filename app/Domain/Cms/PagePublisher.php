<?php

namespace App\Domain\Cms;

use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Draft → publish with numbered versions and restore (CMS-02). Publishing bumps the site cache version.
 */
class PagePublisher
{
    public const CACHE_KEY = 'site.content_version';

    public function publish(Page $page, ?User $user = null, ?string $note = null): PageVersion
    {
        return DB::transaction(function () use ($page, $user, $note) {
            $page = Page::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $number = (int) $page->versions()->max('number') + 1;
            $version = PageVersion::create([
                'page_id' => $page->id,
                'number' => $number,
                'content' => $page->draft,
                'note' => $note,
                'created_by' => $user?->id,
                'created_at' => now(),
            ]);
            $page->forceFill(['published' => $page->draft, 'published_at' => now(), 'published_by' => $user?->id])->save();
            app(AuditLogger::class)->log('cms.page_published', $page, ['version' => $number], $user);
            DB::afterCommit(fn () => self::bumpCache());

            return $version;
        });
    }

    /** Copies an old version into the draft; it goes live only when published again. */
    public function restoreToDraft(Page $page, PageVersion $version, ?User $user = null): void
    {
        abort_unless($version->page_id === $page->id, 404);
        $page->forceFill(['draft' => $version->content, 'updated_by' => $user?->id, 'lock_version' => $page->lock_version + 1])->save();
        app(AuditLogger::class)->log('cms.page_restored', $page, ['version' => $version->number], $user);
    }

    public static function bumpCache(): void
    {
        Cache::forever(self::CACHE_KEY, (int) Cache::get(self::CACHE_KEY, 1) + 1);
    }
}
