<?php

namespace App\Modules\Display\Services;

use App\Modules\Arena\Models\GameSession;
use App\Modules\Arena\Models\SessionParticipant;
use App\Modules\Arena\Services\AvatarCatalog;
use App\Modules\Arena\Services\LegendsService;
use App\Modules\Booking\Models\Booking;
use App\Modules\Catalog\Models\Game;
use App\Modules\Display\Models\DisplayAsset;
use App\Modules\Display\Models\DisplayScreen;
use App\Support\LocalTime;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Starea unui ecran, trimisă playerului. Conține exclusiv identitatea publică aprobată (nickname + avatar):
 * niciun nume, telefon, e-mail sau dată de rezervare. Participanții fără acord de afișare apar ca „Explorator 03”.
 * Timpul scurs se calculează în player din momentul pornirii, astfel încât starea nu se schimbă la fiecare secundă.
 */
class DisplayStateBuilder
{
    /** Cât timp rămâne „Misiune încheiată” disponibil după finalizare. */
    public const ENDED_WINDOW_MINUTES = 3;

    public function __construct(private readonly LegendsService $legends) {}

    public function build(DisplayScreen $screen): array
    {
        $now = CarbonImmutable::now();
        $local = LocalTime::now();
        $config = $screen->config();

        $state = [
            'screen' => ['slug' => $screen->slug, 'name' => $screen->name, 'type' => $screen->type, 'title' => $config['title'], 'subtitle' => $config['subtitle']],
            'settings' => array_intersect_key($config, array_flip([
                'show_clock', 'animation', 'volume', 'intro', 'live_ads', 'live_ad_seconds', 'live_quiet_minutes', 'live_uninterrupted',
                'offline_after_seconds', 'poll_seconds', 'top_n', 'page_seconds',
            ])),
            'override' => $screen->activeOverride(),
            'reload_at' => $screen->reload_requested_at?->toIso8601ZuluString(),
            'playlist' => $this->playlist($screen, $local),
            'assets' => $this->assets($screen, $now),
            'games' => $this->games(),
            'brand' => $this->brand($config),
        ];

        if ($screen->type === 'live_arena') {
            $state['live'] = $this->live($screen, $now);
        } else {
            $state['legends'] = $this->legendsBlock($config);
        }

        return $state;
    }

    private function playlist(DisplayScreen $screen, CarbonImmutable $local): array
    {
        $playlist = $screen->playlist;
        $items = [];
        if ($playlist && $playlist->is_active) {
            foreach ($playlist->items()->get() as $item) {
                if (! $item->isScheduledAt($local)) {
                    continue;
                }
                $type = $item->type === 'main' ? ($screen->type === 'live_arena' ? 'live' : 'leaderboard') : $item->type;
                $items[] = array_filter([
                    'id' => $item->id,
                    'type' => $type,
                    'main' => $item->type === 'main' || ($screen->type === 'hall_of_legends' && $item->type === 'leaderboard'),
                    'duration' => max(3, (int) $item->duration_seconds),
                    'weight' => max(1, (int) $item->weight),
                    'asset_id' => $item->display_asset_id,
                    'game_id' => $item->game_id,
                    'message' => $item->message,
                ], fn ($v) => $v !== null);
            }
        }
        if (! collect($items)->contains(fn ($i) => ! empty($i['main']))) {
            // fără conținut principal configurat: ecranul își păstrează rolul
            array_unshift($items, ['id' => 0, 'type' => $screen->type === 'live_arena' ? 'live' : 'leaderboard', 'main' => true, 'duration' => 50, 'weight' => 10]);
        }

        return [
            'mode' => $playlist?->mode ?? 'smart',
            'min_main' => (int) ($playlist?->min_main_seconds ?? 45),
            'max_ads' => max(1, (int) ($playlist?->max_consecutive_ads ?? 2)),
            'items' => $items,
        ];
    }

    private function assets(DisplayScreen $screen, CarbonImmutable $now): array
    {
        $out = [];
        $override = $screen->activeOverride();
        foreach (DisplayAsset::query()->with('media.poster')->get() as $asset) {
            $forced = $override && ($override['command'] ?? null) === 'asset' && (int) ($override['asset_id'] ?? 0) === $asset->id;
            if (! $forced && (! $asset->appliesTo($screen->type) || ! $asset->isLiveAt($now))) {
                continue;
            }
            if (! $asset->media || ! $asset->media->isPublishable()) {
                continue;
            }
            $media = $asset->media;
            $isVideo = $asset->kind === 'video';
            $out[] = array_filter([
                'id' => $asset->id,
                'kind' => $asset->kind,
                'url' => $isVideo ? $media->originalUrl() : $media->urlForWidth(1920, ['webp', 'jpg']),
                'poster' => $isVideo && $media->poster ? $media->poster->urlForWidth(1280, ['webp', 'jpg']) : null,
                'fit' => $asset->fit,
                'orientation' => $media->width && $media->height && $media->height > $media->width ? 'portrait' : 'landscape',
                'caption' => $asset->caption,
                'duration' => max(3, min(600, (int) $asset->duration_seconds)),
                'weight' => max(1, (int) $asset->weight),
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    private function games(): array
    {
        return Cache::remember('display:games:'.DisplayVersion::current(), 300, fn () => Game::query()->visibleOnSite()->publishedIn('ro')
            ->with(['cover', 'trailer'])->limit(16)->get()
            ->filter(fn (Game $g) => $g->cover && $g->cover->isPublishable())
            ->map(fn (Game $g) => array_filter([
                'id' => $g->id,
                'title' => (string) $g->getTranslation('title', 'ro'),
                'tagline' => $g->getTranslation('tagline', 'ro', false) ?: null,
                'image' => $g->cover->urlForWidth(1920, ['webp', 'jpg']),
                'min_age' => $g->min_age ? $g->min_age.'+' : null,
                'players' => $g->playersLabel(),
                'minutes' => $g->play_minutes ? $g->play_minutes.' min' : null,
            ], fn ($v) => $v !== null))->values()->all());
    }

    private function brand(array $config): array
    {
        $url = filled($config['qr_url']) ? $config['qr_url'] : lroute('booking.show', [], 'ro');

        return [
            'qr' => Cache::rememberForever('display:qr:'.md5($url), fn () => $this->qr($url)),
            'qr_text' => $config['qr_text'],
            'qr_label' => preg_replace('#^https?://(www\.)?#', '', rtrim(explode('?', $url)[0], '/')),
        ];
    }

    private function qr(string $url): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(320, 0), new SvgImageBackEnd)))->writeString($url);

        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }

    private function live(DisplayScreen $screen, CarbonImmutable $now): array
    {
        $base = GameSession::query()->with(['game.cover', 'participants.player'])
            ->when($screen->resource_id, fn ($q) => $q->where('resource_id', $screen->resource_id));

        $current = (clone $base)->whereIn('status', GameSession::RUNNING)->latest('started_at')->first();
        $ended = $current ? null : (clone $base)->where('status', 'completed')->where('is_test', false)
            ->where('ended_at', '>=', $now->subMinutes(self::ENDED_WINDOW_MINUTES))->latest('ended_at')->first();

        return [
            'session' => $current ? $this->sessionBlock($current) : null,
            'ended' => $ended ? $this->sessionBlock($ended) + ['ended_at' => $ended->ended_at->toIso8601ZuluString()] : null,
            'next' => $this->next($screen, $now, $current),
        ];
    }

    private function sessionBlock(GameSession $session): array
    {
        $participants = $session->participants->where('status', 'active')->sortBy('slot')->values();
        $public = $participants->filter(fn (SessionParticipant $p) => $p->player->isPublic());
        $counts = $this->legends->allTimeCounts($public->pluck('player_id')->all());
        $withResults = $session->results_source !== null && $session->result_type !== 'none';

        $players = $participants->map(function (SessionParticipant $p) use ($counts, $withResults, $session) {
            $isPublic = $p->player->isPublic();

            return array_filter([
                'k' => 'p'.$p->id,
                'name' => $isPublic ? $p->player->nickname : $p->alias(),
                'avatar' => $isPublic ? AvatarCatalog::url($p->player->avatar_key) : AvatarCatalog::url(null),
                'alias' => ! $isPublic,
                'level' => $isPublic ? $this->legends->levelFor((int) ($counts[$p->player_id] ?? 0)) : null,
                'place' => $withResults ? $p->place : null,
                'result' => $withResults ? $this->resultLabel($p, $session->result_type) : null,
                'team' => $withResults && $session->result_type === 'team_score' ? $p->team : null,
            ], fn ($v) => $v !== null && $v !== false);
        })->values()->all();

        $game = $session->game;

        return [
            'id' => $session->id,
            'status' => $session->status,
            'game' => array_filter([
                'title' => $game ? (string) $game->getTranslation('title', 'ro') : 'Aventura VR',
                'image' => $game?->cover && $game->cover->isPublishable() ? $game->cover->urlForWidth(1920, ['webp', 'jpg']) : null,
            ]),
            'started_at' => $session->started_at?->toIso8601ZuluString(),
            'paused_at' => $session->status === 'paused' ? $session->paused_at?->toIso8601ZuluString() : null,
            'paused_seconds' => (int) $session->paused_seconds,
            'planned_seconds' => (int) $session->planned_minutes * 60,
            'count' => count($players),
            'players' => $players,
            'results' => $withResults ? ['type' => $session->result_type, 'source' => $session->results_source] : null,
            'podium' => $withResults ? $this->podium($participants, $session) : null,
        ];
    }

    /** Podiumul apare numai din rezultate reale; pentru echipe, podiumul este al echipelor. */
    private function podium($participants, GameSession $session): array
    {
        if ($session->result_type === 'team_score') {
            return $participants->groupBy('team')->map(fn ($members, $team) => [
                'place' => (int) $members->first()->place,
                'name' => (string) $team,
                'result' => number_format((int) $members->first()->score, 0, ',', '.').' pct',
                'members' => $members->map(fn ($m) => $m->player->isPublic() ? $m->player->nickname : $m->alias())->values()->all(),
            ])->sortBy('place')->take(3)->values()->all();
        }

        return $participants->filter(fn ($p) => $p->place !== null && $p->place <= 3)->sortBy([['place', 'asc'], ['slot', 'asc']])
            ->map(fn (SessionParticipant $p) => [
                'place' => (int) $p->place,
                'name' => $p->player->isPublic() ? $p->player->nickname : $p->alias(),
                'avatar' => $p->player->isPublic() ? AvatarCatalog::url($p->player->avatar_key) : AvatarCatalog::url(null),
                'result' => $this->resultLabel($p, $session->result_type),
            ])->values()->all();
    }

    private function resultLabel(SessionParticipant $p, string $type): ?string
    {
        return match ($type) {
            'score_high', 'team_score' => $p->score !== null ? number_format($p->score, 0, ',', '.').' pct' : null,
            'time_low' => $p->time_ms !== null ? self::formatTime($p->time_ms) : null,
            default => null,
        };
    }

    public static function formatTime(int $ms): string
    {
        $minutes = intdiv($ms, 60000);
        $seconds = intdiv($ms % 60000, 1000);

        return sprintf('%d:%02d.%03d', $minutes, $seconds, $ms % 1000);
    }

    /** Doar ora următoarei sesiuni, fără niciun detaliu despre participanți. */
    private function next(DisplayScreen $screen, CarbonImmutable $now, ?GameSession $current = null): ?array
    {
        $limit = $now->addDays(7);
        $session = GameSession::query()->whereIn('status', GameSession::OPEN)->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$now, $limit])
            ->when($current, fn ($q) => $q->whereKeyNot($current->id))
            ->when($screen->resource_id, fn ($q) => $q->where('resource_id', $screen->resource_id))
            ->orderBy('scheduled_at')->value('scheduled_at');
        $booking = Booking::query()->whereIn('status', ['confirmed', 'checked_in'])->whereBetween('starts_at', [$now, $limit])
            ->when($current?->booking_id, fn ($q) => $q->whereKeyNot($current->booking_id))
            ->when($screen->resource_id, fn ($q) => $q->whereHas('occupancies', fn ($o) => $o->where('is_active', true)
                ->whereIn('resource_id', $screen->resource?->conflictFamilyIds() ?? [$screen->resource_id])))
            ->orderBy('starts_at')->value('starts_at');
        $times = array_filter([$session ? CarbonImmutable::parse($session) : null, $booking ? CarbonImmutable::parse($booking) : null]);
        if (! $times) {
            return null;
        }
        $at = min($times);
        $localAt = LocalTime::toLocal($at);
        $today = LocalTime::now()->startOfDay();
        $days = [1 => 'Luni', 'Marți', 'Miercuri', 'Joi', 'Vineri', 'Sâmbătă', 'Duminică'];
        $label = match (true) {
            $localAt->isSameDay($today) => 'Azi, '.$localAt->format('H:i'),
            $localAt->isSameDay($today->addDay()) => 'Mâine, '.$localAt->format('H:i'),
            default => $days[$localAt->isoWeekday()].', '.$localAt->format('H:i'),
        };

        return ['at' => $at->toIso8601ZuluString(), 'time' => $localAt->format('H:i'), 'label' => $label];
    }

    private function legendsBlock(array $config): array
    {
        $period = $this->legends->period((string) $config['period'], $config['period_from'], $config['period_to']);
        $top = max(3, min(20, (int) $config['top_n']));
        $rows = Cache::remember('display:legends:'.md5(json_encode([$period['label'], $period['from'], $top])).':'.DisplayVersion::current(), 30,
            fn () => $this->legends->ranking($period['from'], $period['to'], $top + 40));

        return [
            'period' => $period['label'],
            'metric' => $config['subtitle'],
            'rows' => array_map(fn (array $row) => [
                'k' => 'l'.$row['player_id'],
                'rank' => $row['rank'],
                'name' => $row['nickname'],
                'avatar' => AvatarCatalog::url($row['avatar_key']),
                'sessions' => $row['sessions'],
                'level' => $row['level'],
            ], $rows),
        ];
    }
}
