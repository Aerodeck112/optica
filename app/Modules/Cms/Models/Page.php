<?php

namespace App\Modules\Cms\Models;

use App\Modules\Core\Concerns\PublishedInLocales;
use App\Support\Locales;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * Pagină compusă din blocuri controlate. Ciorna (draft_blocks) este separată de versiunea publicată (blocks);
 * fiecare salvare/publicare creează o revizie care poate fi restaurată.
 */
class Page extends Model
{
    use HasTranslations, PublishedInLocales;

    protected $table = 'pages';

    protected $guarded = ['id'];

    public array $translatable = ['slug', 'title', 'seo_title', 'seo_description'];

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'draft_blocks' => 'array',
            'draft_meta' => 'array',
            'published_locales' => 'array',
            'has_draft' => 'boolean',
            'noindex' => 'boolean',
            'is_system' => 'boolean',
            'published_at' => 'immutable_datetime',
            'scheduled_at' => 'immutable_datetime',
        ];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PageRevision::class)->latest('created_at');
    }

    public function ogImage(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'og_image_id');
    }

    public function isLive(): bool
    {
        return $this->status === 'published' && $this->published_at !== null && $this->published_at->isPast();
    }

    /** Blocurile publicate pentru o limbă, fără cele ascunse. */
    public function visibleBlocks(string $locale, bool $draft = false): array
    {
        $source = $draft ? ($this->draft_blocks ?? $this->blocks) : $this->blocks;
        $blocks = $source[$locale] ?? ($locale !== Locales::DEFAULT ? [] : ($source[Locales::DEFAULT] ?? []));

        return array_values(array_filter((array) $blocks, fn ($block) => ! ($block['data']['hidden'] ?? false)));
    }

    public function localizedSlug(string $locale): string
    {
        return (string) $this->getTranslation('slug', $locale, false);
    }

    public function url(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $slug = $this->localizedSlug($locale);

        return url(Locales::prefix($locale).($slug === '' ? '/' : '/'.$slug));
    }
}
