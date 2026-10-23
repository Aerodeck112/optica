<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Cms\Models\Media;
use App\Modules\Core\Concerns\PublishedInLocales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * Pachet rezervabil (sesiune, aniversare, eveniment). Dacă prețul, durata sau capacitatea lipsesc,
 * pachetul acceptă doar cereri de disponibilitate — nu confirmări automate.
 */
class Package extends Model
{
    use HasTranslations, PublishedInLocales;

    public const TYPES = [
        'session' => 'Sesiune de joc',
        'birthday' => 'Aniversare',
        'event' => 'Team building / eveniment',
        'private' => 'Eveniment privat',
    ];

    protected $table = 'packages';

    protected $guarded = ['id'];

    public array $translatable = ['slug', 'name', 'summary', 'description', 'included', 'conditions', 'seo_title', 'seo_description'];

    protected function casts(): array
    {
        return [
            'resource_ids' => 'array',
            'published_locales' => 'array',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'use_in_ai' => 'boolean',
            'requires_adult_contact' => 'boolean',
        ];
    }

    public function games(): BelongsToMany
    {
        return $this->belongsToMany(Game::class, 'package_game');
    }

    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(Addon::class, 'addon_package');
    }

    public function priceRules(): HasMany
    {
        return $this->hasMany(PriceRule::class)->orderBy('priority');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort');
    }

    /** Are tot ce trebuie pentru a calcula disponibilitatea: durată, capacitate și resurse. */
    public function hasScheduling(): bool
    {
        return $this->slot_minutes > 0 && $this->max_participants > 0 && ! empty($this->resource_ids);
    }

    public function hasPrice(): bool
    {
        return $this->price_minor !== null;
    }

    /** Poate fi rezervat instant (cu slot blocat), nu doar ca cerere. */
    public function supportsInstantBooking(): bool
    {
        return $this->booking_mode === 'instant' && $this->hasScheduling() && $this->hasPrice();
    }

    public function occupiedMinutes(int $extraMinutes = 0): int
    {
        return (int) $this->buffer_before_minutes + (int) $this->slot_minutes + $extraMinutes + (int) $this->buffer_after_minutes;
    }
}
