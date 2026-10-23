<?php

namespace App\Modules\Core\Concerns;

use App\Support\Locales;
use Illuminate\Database\Eloquent\Builder;

/**
 * Conținut traductibil publicat pe limbi. O limbă apare public (URL, hreflang, sitemap)
 * doar dacă se află în published_locales.
 */
trait PublishedInLocales
{
    public function isPublishedIn(string $locale): bool
    {
        return in_array($locale, $this->publishedLocales(), true);
    }

    public function publishedLocales(): array
    {
        $locales = (array) ($this->published_locales ?? []);

        return array_values(array_intersect($locales, Locales::enabled()));
    }

    public function scopePublishedIn(Builder $query, string $locale): Builder
    {
        return $query->whereJsonContains('published_locales', $locale);
    }
}
