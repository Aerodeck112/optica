<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Blocks\BlockRenderer;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Seo\Seo;
use App\Modules\Cms\Seo\StructuredData;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PageController extends Controller
{
    public function __construct(
        private readonly BlockRenderer $renderer,
        private readonly StructuredData $structured,
    ) {}

    public function __invoke(Request $request, ?string $slug = null): Response
    {
        $locale = app()->getLocale();

        $page = $slug === null
            ? Page::query()->where('key', 'home')->first()
            : Page::query()->where("slug->{$locale}", $slug)->first();

        abort_unless($page && $page->isLive() && $page->isPublishedIn($locale), 404);

        return response()->view('site.pages.blocks', $this->viewData($page, $locale, false));
    }

    public function viewData(Page $page, string $locale, bool $draft): array
    {
        $blocks = $this->renderer->prepare($page->visibleBlocks($locale, $draft), $locale);

        $title = $page->getTranslation('seo_title', $locale, false) ?: $page->getTranslation('title', $locale);
        $seo = Seo::make($title, $page->getTranslation('seo_description', $locale, false) ?: null);
        $seo->canonical = $page->url($locale);
        $seo->noindex($page->noindex || $draft);
        $seo->alternates(collect($page->publishedLocales())->mapWithKeys(fn ($l) => [$l => $page->url($l)])->all());
        if ($page->ogImage) {
            $seo->image($page->ogImage->urlForWidth(1200), $page->ogImage->altText($locale));
        }

        if ($page->key === 'home') {
            $seo->addJsonLd($this->structured->business())->addJsonLd($this->structured->website());
        } else {
            $seo->breadcrumbs([
                ['name' => __('site.brand'), 'url' => url('/')],
                ['name' => $page->getTranslation('title', $locale), 'url' => $page->url($locale)],
            ]);
        }
        if ($page->key === 'faq' && $this->renderer->faqItems) {
            $seo->addJsonLd($this->structured->faq($this->renderer->faqItems));
        }

        $startsWithHero = ($blocks[0]['type'] ?? null) === 'hero_portal';

        return [
            'page' => $page,
            'blocks' => $blocks,
            'seo' => $seo,
            'showPageHero' => ! $startsWithHero,
            'isPreview' => $draft,
        ];
    }
}
