<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Cms\Models\Media;
use App\Modules\Core\Concerns\PublishedInLocales;
use App\Support\Locales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Translatable\HasTranslations;

/**
 * Joc VR. Vârsta minimă, numărul de jucători, durata și intensitatea sunt câmpuri „confirmate pentru Cluj”:
 * cât timp sunt goale, nu se afișează public. Valorile publicate de franciză stau în reference_data, doar ca referință internă.
 */
class Game extends Model
{
    use HasTranslations, PublishedInLocales;

    public const STATUSES = [
        'available' => 'Disponibil la Cluj',
        'coming_soon' => 'În curând la Cluj',
        'unconfirmed' => 'Neconfirmat pentru Cluj',
        'retired' => 'Retras',
    ];

    public const AUDIENCES = ['all' => 'Toate vârstele (în limita vârstei minime)', 'kids' => 'Potrivit pentru copii', 'teens_adults' => 'Adolescenți și adulți'];

    public const INTENSITY = [1 => 'Calm', 2 => 'Dinamic', 3 => 'Intens'];

    protected $table = 'games';

    protected $guarded = ['id'];

    /** Valorile implicite din baza de date, disponibile și pe modelele abia create. */
    protected $attributes = ['audience' => 'all', 'cluj_status' => 'unconfirmed', 'is_featured' => false, 'use_in_ai' => true, 'sort' => 0];

    public array $translatable = ['slug', 'title', 'tagline', 'description', 'rules', 'seo_title', 'seo_description'];

    protected function casts(): array
    {
        return [
            'reference_data' => 'array',
            'gallery_ids' => 'array',
            'published_locales' => 'array',
            'is_featured' => 'boolean',
            'use_in_ai' => 'boolean',
        ];
    }

    public function genre(): BelongsTo
    {
        return $this->belongsTo(GameGenre::class, 'genre_id');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_id');
    }

    public function trailer(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'trailer_id');
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'package_game');
    }

    public function galleryMedia(): Collection
    {
        $ids = (array) $this->gallery_ids;
        if (! $ids) {
            return new Collection;
        }
        $media = Media::query()->whereIn('id', $ids)->get()->keyBy('id');

        return new Collection(array_values(array_filter(array_map(fn ($id) => $media[$id] ?? null, $ids))));
    }

    /** Jocurile vizibile pe site: disponibile/în curând, plus (opțional) cele neconfirmate, marcate clar. */
    public function scopeVisibleOnSite(Builder $query, bool $includeUnconfirmed = false): Builder
    {
        $statuses = $includeUnconfirmed ? ['available', 'coming_soon', 'unconfirmed'] : ['available', 'coming_soon'];

        return $query->whereIn('cluj_status', $statuses)->orderBy('sort');
    }

    public function isBookable(): bool
    {
        return $this->cluj_status === 'available';
    }

    public function playersLabel(): ?string
    {
        if (! $this->min_players && ! $this->max_players) {
            return null;
        }
        if ($this->min_players && $this->max_players) {
            return $this->min_players === $this->max_players ? (string) $this->min_players : $this->min_players.'–'.$this->max_players;
        }

        return $this->min_players ? 'de la '.$this->min_players : 'până la '.$this->max_players;
    }

    public function url(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return url(Locales::prefix($locale).'/'.trans('routes.games', [], $locale).'/'.$this->getTranslation('slug', $locale, true));
    }
}
