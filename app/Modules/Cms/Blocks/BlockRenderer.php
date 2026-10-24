<?php

namespace App\Modules\Cms\Blocks;

use App\Modules\Catalog\Models\Game;
use App\Modules\Catalog\Models\Package;
use App\Modules\Cms\Models\Faq;
use App\Modules\Cms\Models\GalleryItem;
use App\Modules\Cms\Models\Post;
use App\Modules\Cms\Models\Testimonial;
use App\Modules\Cms\Services\LinkResolver;
use App\Modules\Cms\Services\MediaRepository;
use App\Modules\Cms\Services\SafeMarkdown;
use App\Modules\Core\Settings\SettingsRepository;

/**
 * Transformă datele brute ale blocurilor în date gata de afișare: imagini rezolvate, linkuri validate,
 * liste de conținut (jocuri, FAQ, pachete). Blocurile fără conținut real nu se afișează.
 */
class BlockRenderer
{
    /** @var array<int, array{question:string, answer:string}> colectate pentru date structurate FAQPage */
    public array $faqItems = [];

    public function __construct(
        private readonly MediaRepository $media,
        private readonly LinkResolver $links,
        private readonly SettingsRepository $settings,
    ) {}

    /** @return array<int, array{type:string, view:string, data:array}> */
    public function prepare(array $blocks, string $locale): array
    {
        $this->media->preload(MediaRepository::collectIds($blocks));
        $out = [];
        foreach ($blocks as $index => $block) {
            $type = $block['type'] ?? null;
            if (! $type || ! BlockRegistry::exists($type)) {
                continue;
            }
            $data = $this->{'prepare'.str_replace('_', '', ucwords($type, '_'))}((array) ($block['data'] ?? []), $locale, $index);
            if ($data === null) {
                continue;
            }
            $out[] = ['type' => $type, 'view' => 'site.blocks.'.$type, 'data' => $data + ['anchor' => $block['data']['anchor'] ?? null]];
        }

        return $out;
    }

    private function cta(?string $label, ?string $link, string $locale): ?array
    {
        $url = $this->links->url($link, $locale);

        return $label && $url ? ['label' => $label, 'url' => $url, 'external' => LinkResolver::isExternal($url)] : null;
    }

    private function prepareHeroPortal(array $d, string $locale, int $i): array
    {
        $video = [];
        foreach ((array) ($d['video_sources'] ?? []) as $source) {
            if (! empty($source['src']) && str_starts_with($source['src'], '/')) {
                $video[] = ['src' => $source['src'], 'type' => $source['type'] ?? 'video/mp4'];
            }
        }

        return [
            'location' => $d['location'] ?? __('site.location'),
            'title_1' => $d['title_1'] ?? '',
            'title_2' => $d['title_2'] ?? '',
            'lede' => $d['lede'] ?? null,
            'primary' => $this->cta($d['primary_label'] ?? null, $d['primary_link'] ?? null, $locale),
            'secondary' => $this->cta($d['secondary_label'] ?? null, $d['secondary_link'] ?? null, $locale),
            'aside' => $this->cta($d['aside_label'] ?? null, $d['aside_link'] ?? null, $locale),
            'poster' => $this->media->find($d['poster_image'] ?? null),
            'video' => $video,
            'eager' => $i === 0,
        ];
    }

    private function prepareStatement(array $d): ?array
    {
        return empty($d['text']) ? null : ['text' => $d['text'], 'body' => SafeMarkdown::render($d['body'] ?? null)];
    }

    private function prepareAudiencePaths(array $d, string $locale): ?array
    {
        $items = [];
        foreach ((array) ($d['items'] ?? []) as $item) {
            $url = $this->links->url($item['link'] ?? null, $locale);
            if (empty($item['title'])) {
                continue;
            }
            $items[] = [
                'title' => $item['title'],
                'text' => $item['text'] ?? null,
                'image' => $this->media->find($item['image'] ?? null),
                'url' => $url,
                'link_label' => $item['link_label'] ?? __('site.learn_more'),
            ];
        }

        return $items ? ['heading' => $d['heading'] ?? null, 'intro' => $d['intro'] ?? null, 'items' => $items] : null;
    }

    private function prepareGameRail(array $d, string $locale): ?array
    {
        $includeUnconfirmed = $this->settings->bool('site.show_unconfirmed_games');
        $query = Game::query()->visibleOnSite($includeUnconfirmed)->publishedIn($locale)->with(['cover', 'genre']);
        if (($d['source'] ?? 'featured') === 'featured') {
            $query->orderByDesc('is_featured');
        }
        $games = $query->limit((int) ($d['limit'] ?? 8))->get();
        if ($games->isEmpty()) {
            return null;
        }

        return [
            'heading' => $d['heading'] ?? __('site.games.title'),
            'intro' => $d['intro'] ?? null,
            'games' => $games,
            'cta' => $this->cta($d['cta_label'] ?? __('site.see_all_games'), 'route:games', $locale),
        ];
    }

    private function prepareFeatureSplit(array $d, string $locale): ?array
    {
        if (empty($d['heading'])) {
            return null;
        }

        return [
            'heading' => $d['heading'],
            'body' => $d['body'] ?? null,
            'bullets' => array_values(array_filter(array_map(fn ($b) => is_array($b) ? ($b['text'] ?? null) : $b, (array) ($d['bullets'] ?? [])))),
            'image' => $this->media->find($d['image'] ?? null),
            'image_side' => $d['image_side'] ?? 'left',
            'image_shape' => $d['image_shape'] ?? 'portrait',
            'tone' => $d['tone'] ?? 'default',
            'primary' => $this->cta($d['primary_label'] ?? null, $d['primary_link'] ?? null, $locale),
            'secondary' => $this->cta($d['secondary_label'] ?? null, $d['secondary_link'] ?? null, $locale),
        ];
    }

    private function prepareSteps(array $d): ?array
    {
        $steps = array_values(array_filter((array) ($d['steps'] ?? []), fn ($s) => ! empty($s['title'])));

        return $steps ? ['heading' => $d['heading'] ?? null, 'intro' => $d['intro'] ?? null, 'steps' => $steps] : null;
    }

    private function prepareImageBand(array $d): ?array
    {
        $image = $this->media->find($d['image'] ?? null);

        return $image ? ['image' => $image, 'text' => $d['text'] ?? null] : null;
    }

    private function prepareGalleryMosaic(array $d, string $locale): ?array
    {
        $images = collect();
        if (! empty($d['images'])) {
            $images = $this->media->many((array) $d['images']);
        } else {
            $images = GalleryItem::query()
                ->where('is_published', true)
                ->when(! empty($d['album']) && $d['album'] !== 'all', fn ($q) => $q->where('album', $d['album']))
                ->orderBy('sort')
                ->limit((int) ($d['limit'] ?? 12))
                ->with('media')
                ->get()
                ->map(fn ($item) => $item->media)
                ->filter(fn ($m) => $m && $m->isPublishable());
        }

        return $images->isNotEmpty() ? ['heading' => $d['heading'] ?? null, 'intro' => $d['intro'] ?? null, 'images' => $images->values()] : null;
    }

    private function prepareTestimonials(array $d, string $locale): ?array
    {
        $reviews = Testimonial::query()->where('is_published', true)->where('publication_consent', true)->orderBy('sort')->limit((int) ($d['limit'] ?? 6))->get();

        return $reviews->isNotEmpty() ? ['heading' => $d['heading'] ?? null, 'reviews' => $reviews, 'link' => $this->settings->filled('venue.maps_url')] : null;
    }

    private function prepareFaqList(array $d, string $locale): ?array
    {
        $faqs = Faq::query()->published()->publishedIn($locale)
            ->when(! empty($d['category']) && $d['category'] !== 'all', fn ($q) => $q->where('category', $d['category']))
            ->orderBy('category')->orderBy('sort')
            ->limit((int) ($d['limit'] ?? 50))
            ->get();
        if ($faqs->isEmpty()) {
            return null;
        }
        foreach ($faqs as $faq) {
            $this->faqItems[] = ['question' => $faq->getTranslation('question', $locale), 'answer' => SafeMarkdown::plain($faq->getTranslation('answer', $locale), 2000)];
        }

        return ['heading' => $d['heading'] ?? null, 'grouped' => (bool) ($d['grouped'] ?? false), 'faqs' => $faqs];
    }

    private function preparePackagesTable(array $d, string $locale): array
    {
        $packages = Package::query()->active()->publishedIn($locale)
            ->when(! empty($d['type']) && $d['type'] !== 'all', fn ($q) => $q->where('type', $d['type']))
            ->with('cover')
            ->get();

        return [
            'heading' => $d['heading'] ?? null,
            'intro' => $d['intro'] ?? null,
            'packages' => $packages,
            'empty_cta' => $this->cta($d['empty_cta_label'] ?? __('site.packages.request'), $d['empty_cta_link'] ?? 'page:contact', $locale),
        ];
    }

    private function prepareLeadForm(array $d): array
    {
        return ['type' => $d['type'] ?? 'birthday', 'heading' => $d['heading'] ?? null, 'intro' => $d['intro'] ?? null];
    }

    private function prepareContactDetails(array $d): array
    {
        return ['heading' => $d['heading'] ?? null, 'intro' => $d['intro'] ?? null];
    }

    private function prepareContactForm(array $d): array
    {
        return ['heading' => $d['heading'] ?? __('site.contact.form_title'), 'intro' => $d['intro'] ?? null];
    }

    private function prepareNotice(array $d): ?array
    {
        return empty($d['body']) ? null : ['heading' => $d['heading'] ?? null, 'body' => SafeMarkdown::render($d['body']), 'tone' => $d['tone'] ?? 'info'];
    }

    private function prepareRichText(array $d): ?array
    {
        return empty($d['body']) ? null : ['heading' => $d['heading'] ?? null, 'body' => SafeMarkdown::render($d['body']), 'width' => $d['width'] ?? 'narrow'];
    }

    private function prepareCtaPortal(array $d, string $locale): array
    {
        return [
            'heading' => $d['heading'] ?? __('site.book_cta'),
            'text' => $d['text'] ?? null,
            'primary' => $this->cta($d['primary_label'] ?? __('site.book_cta'), $d['primary_link'] ?? 'route:booking', $locale),
            'secondary' => $this->cta($d['secondary_label'] ?? null, $d['secondary_link'] ?? null, $locale),
        ];
    }

    private function prepareNewsList(array $d, string $locale): ?array
    {
        $posts = Post::query()->published()->publishedIn($locale)->with('cover')->latest('published_at')->limit((int) ($d['limit'] ?? 3))->get();

        return $posts->isNotEmpty() ? ['heading' => $d['heading'] ?? null, 'posts' => $posts] : null;
    }
}
