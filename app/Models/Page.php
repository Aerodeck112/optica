<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Page extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1];

    protected function casts(): array
    {
        return ['draft' => 'array', 'published' => 'array', 'published_at' => 'datetime', 'is_system' => 'boolean', 'lock_version' => 'integer'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PageVersion::class)->orderByDesc('number');
    }

    public function slug(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'hu' ? $this->slug_hu : $this->slug_ro;
    }

    public function title(?string $locale = null, bool $published = true): string
    {
        $locale ??= app()->getLocale();
        $content = $published ? ($this->published ?? $this->draft) : $this->draft;

        return (string) ($content['title'][$locale] ?? $content['title']['ro'] ?? $this->key);
    }

    public function hasUnpublishedChanges(): bool
    {
        return $this->draft != $this->published;
    }
}
