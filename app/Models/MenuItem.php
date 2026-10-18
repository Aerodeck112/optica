<?php

namespace App\Models;

use App\Support\Ui\SiteUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['label' => 'array', 'is_active' => 'boolean'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return (string) ($this->label[$locale] ?? $this->label['ro'] ?? '');
    }

    public function url(?string $locale = null): string
    {
        if ($this->page) {
            return SiteUrl::path($this->page->slug($locale), $locale);
        }
        if ($this->target === 'booking') {
            return SiteUrl::route('booking', [], $locale);
        }

        return (string) $this->target;
    }

    public function path(?string $locale = null): ?string
    {
        return $this->page?->slug($locale);
    }
}
