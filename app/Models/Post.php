<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['draft' => 'array', 'published' => 'array', 'published_at' => 'datetime', 'starts_on' => 'date', 'ends_on' => 'date'];
    }

    /** Published and, for offers, inside the validity period (in the clinic's date). */
    public function scopeVisible(Builder $query): Builder
    {
        $today = now(config('optica.clinic_timezone'))->toDateString();

        return $query->whereNotNull('published_at')
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today));
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_id');
    }

    public function text(string $field, ?string $locale = null, bool $published = true): string
    {
        $locale ??= app()->getLocale();
        $content = $published ? ($this->published ?? []) : ($this->draft ?? []);

        return (string) ($content[$locale][$field] ?? $content['ro'][$field] ?? '');
    }

    public function slug(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'hu' ? $this->slug_hu : $this->slug_ro;
    }
}
