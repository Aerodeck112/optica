<?php

namespace App\Http\Controllers\Site;

use App\Domain\Cms\SiteCatalog;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Post;
use App\Support\Ui\SiteUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SitePageController extends Controller
{
    public function __construct(private readonly SiteCatalog $catalog) {}

    public function page(Request $request, string $slug = ''): Response
    {
        $locale = app()->getLocale();
        $page = Page::query()->where($locale === 'hu' ? 'slug_hu' : 'slug_ro', $slug)->whereNotNull('published_at')->first();
        if (! $page) {
            return app(NotFoundResponder::class)->respond($request);
        }

        return $this->render($page, $page->published);
    }

    /** Draft preview for editors (CMS-02); never indexed. */
    public function preview(Page $page): Response
    {
        abort_unless(Gate::allows('cms.edit'), 403);

        return $this->render($page, $page->draft, preview: true);
    }

    private function render(Page $page, array $content, bool $preview = false): Response
    {
        $locale = app()->getLocale();
        $seo = $content['seo'][$locale] ?? [];
        $title = ($seo['title'] ?? '') ?: ($content['title'][$locale] ?? $content['title']['ro'] ?? null);

        return response()->view('site.page', [
            'page' => $page,
            'content' => $content,
            'catalog' => $this->catalog,
            'preview' => $preview,
            'meta' => [
                'title' => $page->template === 'home' ? null : $title,
                'description' => ($seo['description'] ?? '') ?: null,
                'noindex' => $preview || (bool) ($seo['noindex'] ?? false),
                'canonical' => SiteUrl::path($page->slug($locale), $locale),
                'alternates' => ['ro' => SiteUrl::path($page->slug_ro, 'ro'), 'hu' => SiteUrl::path($page->slug_hu, 'hu')],
            ],
        ]);
    }

    public function service(Request $request, string $code): Response
    {
        $service = $this->catalog->services()->firstWhere('code', $code);
        if (! $service) {
            return app(NotFoundResponder::class)->respond($request);
        }

        return response()->view('site.service', ['service' => $service, 'catalog' => $this->catalog] + $this->detailMeta('servicii/'.$service->code, $service->publicValue('name'), $service->publicValue('description')));
    }

    public function specialist(Request $request, string $code): Response
    {
        $specialist = $this->catalog->specialists()->firstWhere('code', $code);
        if (! $specialist) {
            return app(NotFoundResponder::class)->respond($request);
        }

        return response()->view('site.specialist', ['specialist' => $specialist, 'catalog' => $this->catalog] + $this->detailMeta('medici/'.$specialist->code, $specialist->display_name, $specialist->publicValue('title')));
    }

    public function location(Request $request, string $code): Response
    {
        $location = $this->catalog->locations()->firstWhere('code', $code);
        if (! $location) {
            return app(NotFoundResponder::class)->respond($request);
        }

        return response()->view('site.location', ['location' => $location, 'catalog' => $this->catalog] + $this->detailMeta('puncte-lucru/'.$location->code, $location->publicValue('name'), $location->publicValue('description')));
    }

    public function offer(Request $request, string $slug): Response
    {
        $field = app()->getLocale() === 'hu' ? 'slug_hu' : 'slug_ro';
        $offer = Post::where('type', 'offer')->visible()->where($field, $slug)->first();
        if (! $offer) {
            return app(NotFoundResponder::class)->respond($request);
        }

        return response()->view('site.offer', ['offer' => $offer] + [
            'meta' => [
                'title' => $offer->text('title'),
                'description' => $offer->text('summary'),
                'canonical' => SiteUrl::path('reduceri-actuale/'.$offer->slug()),
                'alternates' => ['ro' => SiteUrl::path('reduceri-actuale/'.$offer->slug_ro, 'ro'), 'hu' => SiteUrl::path('reduceri-actuale/'.$offer->slug_hu, 'hu')],
                'noindex' => false,
            ],
        ]);
    }

    private function detailMeta(string $path, ?string $title, ?string $description): array
    {
        return ['meta' => [
            'title' => $title,
            'description' => $description ? Str::limit(strip_tags($description), 155) : null,
            'canonical' => SiteUrl::path($path),
            'alternates' => ['ro' => SiteUrl::path($path, 'ro'), 'hu' => SiteUrl::path($path, 'hu')],
            'noindex' => false,
        ]];
    }

    public function sitemap(): Response
    {
        $urls = [];
        foreach (Page::whereNotNull('published_at')->get() as $page) {
            if (! ($page->published['seo']['ro']['noindex'] ?? false)) {
                $urls[] = ['ro' => SiteUrl::path($page->slug_ro, 'ro'), 'hu' => SiteUrl::path($page->slug_hu, 'hu'), 'updated' => $page->published_at];
            }
        }
        foreach ([['servicii', $this->catalog->services()], ['medici', $this->catalog->specialists()], ['puncte-lucru', $this->catalog->locations()]] as [$prefix, $items]) {
            foreach ($items as $item) {
                $urls[] = ['ro' => SiteUrl::path($prefix.'/'.$item->code, 'ro'), 'hu' => SiteUrl::path($prefix.'/'.$item->code, 'hu'), 'updated' => $item->published_at];
            }
        }
        foreach ($this->catalog->offers() as $offer) {
            $urls[] = ['ro' => SiteUrl::path('reduceri-actuale/'.$offer->slug_ro, 'ro'), 'hu' => SiteUrl::path('reduceri-actuale/'.$offer->slug_hu, 'hu'), 'updated' => $offer->published_at];
        }
        $urls[] = ['ro' => SiteUrl::route('booking', [], 'ro'), 'hu' => SiteUrl::route('booking', [], 'hu'), 'updated' => null];

        return response()->view('site.sitemap', ['urls' => $urls])->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots(): Response
    {
        if (config('optica.test_site')) {
            return response("User-agent: *\nDisallow: /\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $lines = [
            'User-agent: *',
            'Disallow: /crm',
            'Disallow: /admin',
            'Disallow: /livewire',
            'Disallow: /programare/gestionare',
            'Disallow: /hu/programare/gestionare',
            'Disallow: /previzualizare',
            '',
            'Sitemap: '.url('sitemap.xml'),
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
