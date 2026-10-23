<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Game;
use App\Modules\Cms\Models\LegalDocument;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\Post;
use App\Modules\Core\Settings\SettingsRepository;
use App\Support\Locales;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SeoController extends Controller
{
    public function sitemap(SettingsRepository $settings): Response
    {
        $xml = Cache::remember('aw.sitemap.v1', 600, function () use ($settings) {
            $entries = [];
            $add = function (array $urls, ?string $lastmod = null) use (&$entries) {
                // $urls: locale => url (doar limbile publicate) — generează hreflang reciproc
                foreach ($urls as $url) {
                    $entries[] = ['loc' => $url, 'alternates' => count($urls) > 1 ? $urls : [], 'lastmod' => $lastmod];
                }
            };

            foreach (Page::query()->where('status', 'published')->where('noindex', false)->get() as $page) {
                if (! $page->isLive()) {
                    continue;
                }
                $add(collect($page->publishedLocales())->mapWithKeys(fn ($l) => [$l => $page->url($l)])->all(), $page->updated_at?->toAtomString());
            }

            $add(collect(Locales::enabled())->mapWithKeys(fn ($l) => [$l => lroute('games.index', [], $l)])->all());
            $includeUnconfirmed = $settings->bool('site.show_unconfirmed_games');
            foreach (Game::query()->visibleOnSite($includeUnconfirmed)->get() as $game) {
                $add(collect($game->publishedLocales())->mapWithKeys(fn ($l) => [$l => $game->url($l)])->all(), $game->updated_at?->toAtomString());
            }

            foreach (Post::query()->published()->get() as $post) {
                $add(collect($post->publishedLocales())->mapWithKeys(fn ($l) => [$l => lroute('news.show', ['slug' => $post->getTranslation('slug', $l)], $l)])->all(), $post->updated_at?->toAtomString());
            }

            foreach (array_keys(LegalDocument::TYPES) as $type) {
                if ($doc = LegalDocument::current($type)) {
                    $add(['ro' => lroute('legal.show', ['slug' => $doc->getTranslation('slug', 'ro')], 'ro')], $doc->updated_at?->toAtomString());
                }
            }

            $booking = collect(Locales::enabled())->mapWithKeys(fn ($l) => [$l => lroute('booking.show', [], $l)])->all();
            $add($booking);

            return view('site.sitemap', ['entries' => $entries])->render();
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    public function robots(): Response
    {
        if (config('aw.is_staging') || ! app()->isProduction()) {
            $body = "User-agent: *\nDisallow: /\n";
        } else {
            $body = implode("\n", [
                'User-agent: *',
                'Disallow: /admin',
                'Disallow: /api/',
                'Disallow: /forms/',
                'Disallow: /account/',
                'Disallow: /booking/',
                'Disallow: /cms-preview/',
                'Disallow: /unsubscribe/',
                'Disallow: /*?t=',
                '',
                'Sitemap: '.route('sitemap'),
            ])."\n";
        }

        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
