<?php

namespace App\Modules\Cms\Services;

use App\Modules\Audit\AuditLogger;
use App\Modules\Cms\Blocks\BlockRegistry;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\PageRevision;
use App\Modules\Cms\Models\Redirect;
use App\Support\Locales;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fluxul editorial al paginilor: ciornă → previzualizare → publicare (imediată sau programată),
 * cu revizii restaurabile. Versiunea publicată nu se schimbă până la „Publică”.
 */
class PageEditor
{
    public const META = ['title', 'slug', 'seo_title', 'seo_description'];

    public function __construct(private readonly AuditLogger $audit, private readonly MediaUsageSync $usage) {}

    /** Datele pentru formular: ciorna dacă există, altfel versiunea publicată. */
    public function formState(Page $page): array
    {
        $meta = $page->has_draft && is_array($page->draft_meta) ? $page->draft_meta : [];
        $state = [];
        foreach (self::META as $field) {
            $state[$field] = $meta[$field] ?? $page->getTranslations($field);
        }
        $state['published_locales'] = $meta['published_locales'] ?? ($page->published_locales ?: [Locales::DEFAULT]);
        $state['blocks'] = $page->has_draft && $page->draft_blocks !== null ? $page->draft_blocks : ($page->blocks ?? []);
        foreach (Locales::codes() as $locale) {
            $state['blocks'][$locale] ??= [];
        }

        return $state;
    }

    public function saveDraft(Page $page, array $state, ?int $userId, ?string $note = null): Page
    {
        $blocks = $this->cleanBlocks((array) ($state['blocks'] ?? []));
        $meta = [];
        foreach (self::META as $field) {
            $meta[$field] = array_filter((array) ($state[$field] ?? []), fn ($v) => $v !== null && $v !== '');
        }
        $meta['published_locales'] = array_values(array_intersect((array) ($state['published_locales'] ?? [Locales::DEFAULT]), Locales::codes()));
        $this->validateSlugs($page, $meta['slug'] ?? []);

        return DB::transaction(function () use ($page, $blocks, $meta, $userId, $note) {
            $page->forceFill([
                'draft_blocks' => $blocks,
                'draft_meta' => $meta,
                'has_draft' => true,
                'updated_by' => $userId,
            ])->save();
            $this->revision($page, 'draft_saved', $userId, $note, ['blocks' => $blocks, 'meta' => $meta]);
            $this->syncUsage($page);

            return $page;
        });
    }

    public function publish(Page $page, ?int $userId, ?CarbonInterface $at = null): Page
    {
        if ($at !== null && $at->isFuture()) {
            $page->forceFill(['scheduled_at' => $at, 'status' => $page->status === 'published' ? 'published' : 'scheduled'])->save();
            $this->revision($page, 'scheduled', $userId, 'Programat pentru '.$at->timezone(config('aw.timezone'))->format('d.m.Y H:i'), $this->currentSnapshot($page, true));
            $this->audit->log('cms.page_scheduled', $page, ['at' => $at->toIso8601String()]);

            return $page;
        }

        return DB::transaction(function () use ($page, $userId) {
            $page = Page::query()->lockForUpdate()->findOrFail($page->id);
            $oldSlugs = $page->getTranslations('slug');

            if ($page->has_draft) {
                $meta = (array) $page->draft_meta;
                foreach (self::META as $field) {
                    if (array_key_exists($field, $meta)) {
                        $page->setTranslations($field, (array) $meta[$field]);
                    }
                }
                $page->blocks = $page->draft_blocks ?? $page->blocks;
                if (isset($meta['published_locales'])) {
                    $page->published_locales = $meta['published_locales'];
                }
            }
            $published = array_values(array_intersect((array) $page->published_locales, Locales::codes()));
            $this->assertCompleteTranslations($page, $published);
            if (! in_array(Locales::DEFAULT, $published, true)) {
                $published[] = Locales::DEFAULT;
            }

            $page->forceFill([
                'published_locales' => $published,
                'draft_blocks' => null,
                'draft_meta' => null,
                'has_draft' => false,
                'status' => 'published',
                'published_at' => $page->published_at ?? now(),
                'scheduled_at' => null,
                'updated_by' => $userId,
            ])->save();

            $this->redirectChangedSlugs($page, $oldSlugs);
            $this->revision($page, 'published', $userId, null, $this->currentSnapshot($page, false));
            $this->syncUsage($page);
            $this->audit->log('cms.page_published', $page);

            return $page;
        });
    }

    public function discardDraft(Page $page, ?int $userId): void
    {
        $page->forceFill(['draft_blocks' => null, 'draft_meta' => null, 'has_draft' => false, 'scheduled_at' => null])->save();
        $this->revision($page, 'draft_discarded', $userId, null, $this->currentSnapshot($page, false));
    }

    public function unpublish(Page $page, ?int $userId): void
    {
        if ($page->is_system && $page->key === 'home') {
            throw ValidationException::withMessages(['status' => 'Pagina principală nu poate fi retrasă.']);
        }
        $page->forceFill(['status' => 'draft'])->save();
        $this->revision($page, 'unpublished', $userId, null, $this->currentSnapshot($page, false));
        $this->audit->log('cms.page_unpublished', $page);
    }

    /** Restaurarea pune revizia în ciornă — publicarea rămâne un pas separat, conștient. */
    public function restore(PageRevision $revision, ?int $userId): Page
    {
        $page = $revision->page;
        $snapshot = (array) $revision->snapshot;
        $page->forceFill([
            'draft_blocks' => $snapshot['blocks'] ?? [],
            'draft_meta' => $snapshot['meta'] ?? [],
            'has_draft' => true,
        ])->save();
        $this->revision($page, 'restored', $userId, 'Restaurat din revizia #'.$revision->id, $snapshot);
        $this->audit->log('cms.page_restored', $page, ['revision_id' => $revision->id]);

        return $page;
    }

    /** Publică paginile programate ajunse la termen (rulat din cron). */
    public function publishDue(): int
    {
        $count = 0;
        Page::query()->whereNotNull('scheduled_at')->where('scheduled_at', '<=', now())->each(function (Page $page) use (&$count) {
            $this->publish($page, null);
            $count++;
        });

        return $count;
    }

    private function revision(Page $page, string $reason, ?int $userId, ?string $note, array $snapshot): void
    {
        PageRevision::query()->create([
            'page_id' => $page->id,
            'snapshot' => $snapshot,
            'reason' => $reason,
            'note' => $note,
            'user_id' => $userId,
            'created_at' => now(),
        ]);
        // păstrăm ultimele 60 de revizii per pagină
        $keep = PageRevision::query()->where('page_id', $page->id)->latest('id')->skip(60)->take(1)->value('id');
        if ($keep) {
            PageRevision::query()->where('page_id', $page->id)->where('id', '<=', $keep)->delete();
        }
    }

    private function currentSnapshot(Page $page, bool $preferDraft): array
    {
        if ($preferDraft && $page->has_draft) {
            return ['blocks' => $page->draft_blocks ?? [], 'meta' => $page->draft_meta ?? []];
        }
        $meta = [];
        foreach (self::META as $field) {
            $meta[$field] = $page->getTranslations($field);
        }

        return ['blocks' => $page->blocks ?? [], 'meta' => $meta];
    }

    /** Elimină tipurile necunoscute și normalizează structura {limbă: [{type, data}]}. */
    private function cleanBlocks(array $blocks): array
    {
        $out = [];
        foreach (Locales::codes() as $locale) {
            $out[$locale] = array_values(array_filter(array_map(function ($block) {
                if (! is_array($block) || ! BlockRegistry::exists((string) ($block['type'] ?? ''))) {
                    return null;
                }

                return ['type' => $block['type'], 'data' => (array) ($block['data'] ?? [])];
            }, (array) ($blocks[$locale] ?? []))));
        }

        return $out;
    }

    /** O limbă se publică doar dacă are titlu, adresă și cel puțin un bloc. */
    private function assertCompleteTranslations(Page $page, array $locales): void
    {
        $errors = [];
        foreach ($locales as $locale) {
            $missing = [];
            if (! $page->getTranslation('title', $locale, false)) {
                $missing[] = 'titlu';
            }
            if ($page->key !== 'home' && ! $page->getTranslation('slug', $locale, false)) {
                $missing[] = 'adresă';
            }
            if (empty(($page->blocks ?? [])[$locale] ?? [])) {
                $missing[] = 'blocuri';
            }
            if ($missing) {
                $errors['published_locales'][] = strtoupper($locale).': lipsesc '.implode(', ', $missing).'. Debifează limba sau completează traducerea.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['published_locales' => implode(' ', $errors['published_locales'])]);
        }
    }

    private function validateSlugs(Page $page, array $slugs): void
    {
        foreach ($slugs as $locale => $slug) {
            if ($page->key === 'home') {
                continue;
            }
            if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $slug)) {
                throw ValidationException::withMessages(["slug.{$locale}" => 'Adresa poate conține doar litere mici fără diacritice, cifre și cratime.']);
            }
            $taken = Page::query()->where('id', '!=', $page->id)->where("slug->{$locale}", $slug)->exists();
            if ($taken) {
                throw ValidationException::withMessages(["slug.{$locale}" => 'Există deja o pagină cu această adresă.']);
            }
        }
    }

    private function redirectChangedSlugs(Page $page, array $oldSlugs): void
    {
        foreach ($oldSlugs as $locale => $old) {
            $new = $page->getTranslation('slug', $locale, false);
            if ($old && $new && $old !== $new && $page->isPublishedIn($locale)) {
                $from = Locales::prefix($locale).'/'.$old;
                Redirect::query()->updateOrCreate(['from_path' => $from], ['to_url' => Locales::prefix($locale).'/'.$new, 'status_code' => 301, 'is_active' => true]);
            }
        }
    }

    private function syncUsage(Page $page): void
    {
        $ids = MediaRepository::collectIds(array_merge(...array_values(array_map(fn ($b) => (array) $b, (array) $page->blocks))) ?: []);
        $draftIds = $page->draft_blocks ? MediaRepository::collectIds(array_merge(...array_values(array_map(fn ($b) => (array) $b, (array) $page->draft_blocks))) ?: []) : [];
        $this->usage->sync($page, ['blocks' => $ids, 'draft_blocks' => $draftIds, 'og_image' => [$page->og_image_id]]);
    }
}
