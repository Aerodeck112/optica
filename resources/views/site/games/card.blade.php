<a class="game-card" href="{{ $game->url() }}">
    @if ($game->cover)<x-picture :media="$game->cover" sizes="(max-width: 42rem) 82vw, 22rem" alt=""/>@endif
    <div class="game-card__body">
        <span class="game-card__title">{{ $game->title }}</span>
        <span class="game-card__meta">
            @if ($game->genre)<span class="game-card__tag">{{ $game->genre->name }}</span>@endif
            @if ($game->min_age)<span class="badge">{{ __('site.games.years_plus', ['age' => $game->min_age]) }}</span>@endif
            @if ($game->playersLabel())<span class="badge badge--cyan">{{ $game->playersLabel() }} {{ mb_strtolower(__('site.games.players')) }}</span>@endif
            @if ($game->cluj_status === 'coming_soon')<span class="badge badge--warn">{{ __('site.games.status_coming_soon') }}</span>@endif
            @if ($game->cluj_status === 'unconfirmed')<span class="badge badge--warn">{{ __('site.games.status_unconfirmed') }}</span>@endif
        </span>
        @if ($game->tagline)<span class="game-card__tag">{{ $game->tagline }}</span>@endif
    </div>
</a>
