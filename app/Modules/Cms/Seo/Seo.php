<?php

namespace App\Modules\Cms\Seo;

use App\Support\Locales;
use Illuminate\Support\Str;

/**
 * Metadatele unei pagini publice. Construite de controller, randate în layout.
 */
class Seo
{
    public string $title = '';

    public ?string $description = null;

    public ?string $canonical = null;

    public bool $noindex = false;

    public ?string $image = null;

    public ?string $imageAlt = null;

    public string $type = 'website';

    /** @var array<string, string> limbă → URL (doar traducerile publicate) */
    public array $alternates = [];

    /** @var array<int, array> */
    public array $jsonLd = [];

    /** @var array<int, array{name:string, url:string}> */
    public array $breadcrumbs = [];

    public static function make(string $title, ?string $description = null): self
    {
        $seo = new self;
        $seo->title = $title;
        $seo->description = $description ? Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($description))), 158) : null;
        $seo->canonical = url()->current();

        return $seo;
    }

    public function fullTitle(): string
    {
        $brand = __('site.brand');

        return Str::contains($this->title, $brand) ? $this->title : trim($this->title.' | '.$brand, ' |');
    }

    public function alternates(array $alternates): self
    {
        $this->alternates = array_filter($alternates, fn ($url, $locale) => $url && in_array($locale, Locales::enabled(), true), ARRAY_FILTER_USE_BOTH);

        return $this;
    }

    public function noindex(bool $value = true): self
    {
        $this->noindex = $value;

        return $this;
    }

    public function image(?string $url, ?string $alt = null): self
    {
        $this->image = $url;
        $this->imageAlt = $alt;

        return $this;
    }

    public function breadcrumbs(array $items): self
    {
        $this->breadcrumbs = $items;
        if (count($items) > 1) {
            $this->jsonLd[] = [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => array_map(fn ($item, $i) => [
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'name' => $item['name'],
                    'item' => $item['url'],
                ], $items, array_keys($items)),
            ];
        }

        return $this;
    }

    public function addJsonLd(?array $data): self
    {
        if ($data) {
            $this->jsonLd[] = $data;
        }

        return $this;
    }

    public function robots(): string
    {
        return $this->noindex || config('aw.is_staging') ? 'noindex, nofollow' : 'index, follow, max-image-preview:large';
    }
}
