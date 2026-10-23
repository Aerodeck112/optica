<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Game;
use App\Modules\Catalog\Models\GameGenre;
use App\Modules\Cms\Seo\Seo;
use App\Modules\Cms\Services\SafeMarkdown;
use App\Modules\Core\Settings\SettingsRepository;
use App\Support\Locales;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function index(Request $request)
    {
        $locale = app()->getLocale();
        $includeUnconfirmed = $this->settings->bool('site.show_unconfirmed_games');

        $filters = $request->validate([
            'gen' => ['nullable', 'string', 'max:80'],
            'varsta' => ['nullable', 'integer', 'min:3', 'max:99'],
            'jucatori' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $base = Game::query()->visibleOnSite($includeUnconfirmed)->publishedIn($locale);
        $games = (clone $base)
            ->with(['cover', 'genre'])
            ->when($filters['gen'] ?? null, fn ($q, $slug) => $q->whereHas('genre', fn ($g) => $g->where('slug', $slug)))
            // vârsta: arătăm jocurile cu vârsta minimă confirmată ≤ vârsta aleasă
            ->when($filters['varsta'] ?? null, fn ($q, $age) => $q->whereNotNull('min_age')->where('min_age', '<=', $age))
            ->when($filters['jucatori'] ?? null, fn ($q, $players) => $q->where(fn ($w) => $w->whereNull('min_players')->orWhere('min_players', '<=', $players))
                ->where(fn ($w) => $w->whereNull('max_players')->orWhere('max_players', '>=', $players)))
            ->orderByDesc('is_featured')
            ->orderBy('sort')
            ->get();

        $genres = GameGenre::query()->whereIn('id', (clone $base)->whereNotNull('genre_id')->pluck('genre_id'))->orderBy('sort')->get();
        $hasAges = (clone $base)->whereNotNull('min_age')->exists();
        $hasPlayers = (clone $base)->where(fn ($q) => $q->whereNotNull('min_players')->orWhereNotNull('max_players'))->exists();

        $seo = Seo::make(__('site.games.title').' — Cluj-Napoca', __('site.games.intro'));
        $seo->canonical = lroute('games.index');
        $seo->noindex($request->hasAny(['gen', 'varsta', 'jucatori']));
        $seo->alternates(collect(Locales::enabled())->mapWithKeys(fn ($l) => [$l => lroute('games.index', [], $l)])->all());
        $seo->breadcrumbs([
            ['name' => __('site.brand'), 'url' => url('/')],
            ['name' => __('site.games.title'), 'url' => lroute('games.index')],
        ]);

        return view('site.games.index', [
            'games' => $games,
            'genres' => $genres,
            'filters' => $filters,
            'hasAges' => $hasAges,
            'hasPlayers' => $hasPlayers,
            'totalVisible' => (clone $base)->count(),
            'seo' => $seo,
        ]);
    }

    public function show(string $slug)
    {
        $locale = app()->getLocale();
        $includeUnconfirmed = $this->settings->bool('site.show_unconfirmed_games');

        $game = Game::query()
            ->where("slug->{$locale}", $slug)
            ->visibleOnSite($includeUnconfirmed)
            ->with(['cover', 'genre', 'trailer', 'trailer.poster', 'packages' => fn ($q) => $q->active()])
            ->first();

        abort_unless($game && $game->isPublishedIn($locale), 404);

        $title = $game->getTranslation('seo_title', $locale, false) ?: $game->title;
        $description = $game->getTranslation('seo_description', $locale, false) ?: ($game->tagline ?: SafeMarkdown::plain($game->description, 160));
        $seo = Seo::make($title, $description);
        $seo->canonical = $game->url($locale);
        $seo->alternates(collect($game->publishedLocales())->mapWithKeys(fn ($l) => [$l => $game->url($l)])->all());
        if ($game->cover) {
            $seo->image($game->cover->urlForWidth(1200), $game->cover->altText($locale));
        }
        $seo->breadcrumbs([
            ['name' => __('site.brand'), 'url' => url('/')],
            ['name' => __('site.games.title'), 'url' => lroute('games.index')],
            ['name' => $game->title, 'url' => $game->url($locale)],
        ]);

        $related = Game::query()->visibleOnSite($includeUnconfirmed)->publishedIn($locale)
            ->whereKeyNot($game->id)
            ->when($game->genre_id, fn ($q) => $q->orderByRaw('genre_id = ? desc', [$game->genre_id]))
            ->with(['cover', 'genre'])
            ->limit(6)
            ->get();

        return view('site.games.show', [
            'game' => $game,
            'gallery' => $game->galleryMedia(),
            'related' => $related,
            'seo' => $seo,
        ]);
    }
}
