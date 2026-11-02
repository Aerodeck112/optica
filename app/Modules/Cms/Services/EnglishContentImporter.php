<?php

namespace App\Modules\Cms\Services;

use App\Modules\Catalog\Models\Addon;
use App\Modules\Catalog\Models\Game;
use App\Modules\Catalog\Models\GameGenre;
use App\Modules\Catalog\Models\Package;
use App\Modules\Cms\Models\Banner;
use App\Modules\Cms\Models\Faq;
use App\Modules\Cms\Models\GalleryItem;
use App\Modules\Cms\Models\Media;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Page;
use App\Modules\Notifications\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Model;

/**
 * Adaugă versiunea în engleză a conținutului public, din resources/data/translations-en.json:
 * un dicționar „text românesc → text englezesc” aplicat pe pagini (bloc cu bloc), întrebări frecvente, meniuri,
 * pachete, opțiuni, jocuri, banner, galerie și textele alternative ale imaginilor, plus șabloanele de e-mail.
 * Ce există deja în engleză nu se suprascrie; un text fără traducere rămâne în română (și se raportează).
 */
class EnglishContentImporter
{
    /** Chei din datele blocurilor care nu sunt texte de tradus. */
    private const SKIP_KEYS = ['type', 'image', 'images', 'link', 'anchor', 'tone', 'album', 'poster_image', 'video_sources', 'src',
        'primary_link', 'secondary_link', 'aside_link', 'empty_cta_link', 'image_side', 'image_shape', 'source', 'limit', 'category',
        'grouped', 'hidden', 'cta_link', 'lead_type', 'width'];

    /** @var array<string, string> */
    private array $strings = [];

    /** @var array<int, string> textele românești fără traducere */
    public array $missing = [];

    private array $data = [];

    public function __construct()
    {
        $this->data = json_decode((string) file_get_contents(resource_path('data/translations-en.json')), true) ?: [];
        $this->strings = (array) ($this->data['strings'] ?? []);
    }

    public function import(): void
    {
        $this->pages();
        $this->simple(Faq::query()->get(), ['question', 'answer'], publish: true);
        $this->simple(MenuItem::query()->get(), ['label']);
        $this->simple(Addon::query()->get(), ['name', 'description']);
        $this->simple(GameGenre::query()->get(), ['name']);
        $this->simple(Banner::query()->get(), ['text', 'link_label'], publish: true);
        $this->simple(GalleryItem::query()->get(), ['caption']);
        // imaginile din pachetul pentru rețele (social-*) nu apar pe site cu text alternativ
        $this->simple(Media::query()->whereNotNull('static_key')->where('static_key', 'not like', 'social-%')->get(), ['alt']);
        $this->packages();
        $this->games();
        $this->templates();
        $this->missing = array_values(array_unique($this->missing));
    }

    public function translate(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return $text;
        }
        if (isset($this->strings[$text])) {
            return $this->strings[$text];
        }
        if (preg_match('/\p{L}/u', $text)) {
            $this->missing[] = $text;
        }

        return $text;
    }

    /** Traduce recursiv textele dintr-o structură de bloc, păstrând imaginile, linkurile și opțiunile. */
    public function translateTree(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_string($k) && in_array($k, self::SKIP_KEYS, true)) {
                    continue;
                }
                $value[$k] = $this->translateTree($v, is_string($k) ? $k : $key);
            }

            return $value;
        }
        if (is_string($value) && ! preg_match('#^(page|route):#', $value) && ! str_starts_with($value, '/') && ! str_starts_with($value, '#')) {
            return $this->translate($value);
        }

        return $value;
    }

    private function pages(): void
    {
        foreach (Page::query()->get() as $page) {
            $meta = $this->data['pages'][$page->key] ?? null;
            if (! $meta || $page->getTranslation('title', 'en', false)) {
                continue;
            }
            $page->setTranslation('title', 'en', $meta['title']);
            $page->setTranslation('slug', 'en', $meta['slug']);
            foreach (['seo_title', 'seo_description'] as $field) {
                if ($ro = $page->getTranslation($field, 'ro', false)) {
                    $page->setTranslation($field, 'en', $this->translate($ro));
                }
            }
            foreach (['blocks', 'draft_blocks'] as $column) {
                $blocks = (array) $page->{$column};
                if (isset($blocks['ro']) && ! isset($blocks['en'])) {
                    $blocks['en'] = $this->translateTree($blocks['ro']);
                    $page->{$column} = $blocks;
                }
            }
            $page->published_locales = array_values(array_unique([...(array) $page->published_locales, 'en']));
            $page->save();
        }
    }

    /** @param iterable<Model> $models */
    private function simple(iterable $models, array $fields, bool $publish = false): void
    {
        foreach ($models as $model) {
            $changed = false;
            foreach ($fields as $field) {
                $ro = $model->getTranslation($field, 'ro', false);
                if (! is_string($ro) || $ro === '' || $model->getTranslation($field, 'en', false)) {
                    continue;
                }
                if (! isset($this->strings[$ro])) {
                    $this->missing[] = $ro; // fără traducere: rămâne afișat în română

                    continue;
                }
                $model->setTranslation($field, 'en', $this->strings[$ro]);
                $changed = true;
            }
            if ($publish && $changed) {
                $model->published_locales = array_values(array_unique([...(array) $model->published_locales, 'en']));
            }
            if ($changed) {
                $model->save();
            }
        }
    }

    private function packages(): void
    {
        foreach (Package::query()->get() as $package) {
            if ($package->getTranslation('name', 'en', false)) {
                continue;
            }
            $slug = (string) $package->getTranslation('slug', 'ro', false);
            $package->setTranslation('slug', 'en', $this->data['package_slugs'][$slug] ?? $slug);
            foreach (['name', 'summary', 'description', 'conditions', 'seo_title', 'seo_description'] as $field) {
                if ($ro = $package->getTranslation($field, 'ro', false)) {
                    $package->setTranslation($field, 'en', $this->translate($ro));
                }
            }
            if ($included = $package->getTranslation('included', 'ro', false)) {
                $package->setTranslation('included', 'en', $this->translateTree($included));
            }
            $package->published_locales = array_values(array_unique([...(array) $package->published_locales, 'en']));
            $package->save();
        }
    }

    private function games(): void
    {
        foreach (Game::query()->get() as $game) {
            if ($game->getTranslation('description', 'en', false)) {
                continue;
            }
            $slug = (string) $game->getTranslation('slug', 'ro', false);
            $game->setTranslation('slug', 'en', $this->data['game_slugs'][$slug] ?? $slug);
            $game->setTranslation('title', 'en', (string) $game->getTranslation('title', 'ro'));
            if ($description = $this->data['games'][$slug] ?? null) {
                $game->setTranslation('description', 'en', $description);
            }
            foreach (['tagline', 'seo_title', 'seo_description'] as $field) {
                if ($ro = $game->getTranslation($field, 'ro', false)) {
                    $game->setTranslation($field, 'en', $this->translate($ro));
                }
            }
            $game->published_locales = array_values(array_unique([...(array) $game->published_locales, 'en']));
            $game->save();
        }
    }

    private function templates(): void
    {
        foreach ((array) ($this->data['templates'] ?? []) as $template) {
            NotificationTemplate::query()->firstOrCreate(
                ['key' => $template['key'], 'channel' => $template['channel'], 'locale' => 'en'],
                ['subject' => $template['subject'], 'body' => $template['body'], 'category' => 'service', 'is_active' => true],
            );
        }
    }
}
