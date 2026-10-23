@extends('site.layout')

@php
    $locale = app()->getLocale();
    $bookUrl = lroute('booking.show').'?joc='.$game->getTranslation('slug', $locale);
    $hasFacts = $game->min_age || $game->playersLabel() || $game->play_minutes || $game->intensity;
@endphp

@section('content')
<article data-track-view="view_item" data-track-params='{!! aw_json(['item_name' => $game->getTranslation('title', 'ro'), 'item_category' => $game->genre?->slug]) !!}'>
    <header class="game-hero">
        @if ($game->cover)<x-picture :media="$game->cover" sizes="100vw" :eager="true" alt=""/>@endif
        <div class="wrap game-hero__content">
            <nav aria-label="Breadcrumb"><ol class="breadcrumbs"><li><a href="{{ url('/') }}">{{ __('site.brand') }}</a></li><li><a href="{{ lroute('games.index') }}">{{ __('site.games.title') }}</a></li><li><span aria-current="page">{{ $game->title }}</span></li></ol></nav>
            @if ($game->genre)<p class="badge">{{ $game->genre->name }}</p>@endif
            <h1 class="mt-4">{{ $game->title }}</h1>
            @if ($game->tagline)<p class="hero__lede">{{ $game->tagline }}</p>@endif
            @if ($game->cluj_status === 'coming_soon')<p class="badge badge--warn mt-5">{{ __('site.games.status_coming_soon') }}</p>@endif
            @if ($game->cluj_status === 'unconfirmed')<p class="badge badge--warn mt-5">{{ __('site.games.status_unconfirmed') }}</p>@endif
        </div>
    </header>

    <div class="section section--tight">
        <div class="wrap game-layout">
            <div>
                @if ($hasFacts)
                    <dl class="game-facts">
                        @if ($game->min_age)<div><dt>{{ __('site.games.min_age') }}</dt><dd>{{ __('site.games.years_plus', ['age' => $game->min_age]) }}</dd></div>@endif
                        @if ($game->playersLabel())<div><dt>{{ __('site.games.players') }}</dt><dd>{{ $game->playersLabel() }}</dd></div>@endif
                        @if ($game->play_minutes)<div><dt>{{ __('site.games.play_time') }}</dt><dd>{{ __('site.games.minutes', ['minutes' => $game->play_minutes]) }}</dd></div>@endif
                        @if ($game->intensity)<div><dt>{{ __('site.games.intensity') }}</dt><dd>{{ \App\Modules\Catalog\Models\Game::INTENSITY[$game->intensity] ?? '' }}</dd></div>@endif
                    </dl>
                    @if ($game->play_minutes)<p class="small muted">{{ __('site.games.duration_note') }}</p>@endif
                @else
                    <p class="notice mt-5"><x-aw-icon name="info"/><span>{{ __('site.games.details_pending') }}</span></p>
                @endif

                @if ($game->description)
                    <div class="prose mt-6">{!! \App\Modules\Cms\Services\SafeMarkdown::render($game->description) !!}</div>
                @endif

                @if ($game->trailer)
                    @php $poster = $game->trailer->poster; @endphp
                    <div class="trailer mt-7" data-trailer="{{ $game->trailer->originalUrl() }}" data-label="{{ $game->title }}">
                        @if ($poster)<x-picture :media="$poster" sizes="(max-width: 64rem) 100vw, 60vw" alt=""/>@endif
                        <button type="button"><span><x-aw-icon name="play" style="width:1rem;height:1rem"/>{{ __('site.play_trailer') }}</span></button>
                    </div>
                    <p class="source-note">{{ __('site.trailer_language_note') }}</p>
                @endif

                @if ($gallery->isNotEmpty())
                    <h2 class="mt-7" style="font-size:var(--fs-lg)">{{ __('site.games.gallery') }}</h2>
                    <div class="shots mt-5">
                        @foreach ($gallery as $shot)
                            <button type="button" class="mosaic__item" data-lightbox data-full="{{ $shot->urlForWidth(1600, ['webp', 'jpg']) }}" data-alt="{{ $shot->altText() }}" aria-label="{{ $shot->altText() }}">
                                <x-picture :media="$shot" sizes="(max-width: 42rem) 100vw, 20rem"/>
                            </button>
                        @endforeach
                    </div>
                    <p class="source-note">{{ __('site.game_art_note') }}</p>
                @endif

                @if ($game->rules)
                    <section class="mt-7">
                        <h2 style="font-size:var(--fs-lg)">{{ __('site.games.rules') }}</h2>
                        <div class="prose mt-5">{!! \App\Modules\Cms\Services\SafeMarkdown::render($game->rules) !!}</div>
                    </section>
                @endif
            </div>

            <aside class="game-aside">
                <div class="panel stack">
                    <h2 style="font-size:var(--fs-md)">{{ $game->title }}</h2>
                    @if ($game->isBookable())
                        <a class="btn btn--primary btn--block" href="{{ $bookUrl }}" data-track-click="cta_book" data-track-params='{"position":"game_page"}'>{{ __('site.games.book_this', ['game' => $game->title]) }}</a>
                    @else
                        <a class="btn btn--ghost btn--block" href="{{ app(\App\Modules\Cms\Services\LinkResolver::class)->url('page:contact') ?? lroute('booking.show') }}">{{ __('site.contact.form_title') }}</a>
                    @endif
                    <p class="small muted">{{ __('site.games.duration_note') }}</p>
                </div>
            </aside>
        </div>
    </div>

    @if ($related->isNotEmpty())
        <section class="section section--tight">
            <div class="wrap">
                <h2>{{ __('site.games.related') }}</h2>
                <div class="rail mt-6">
                    @foreach ($related as $other)
                        @include('site.games.card', ['game' => $other])
                    @endforeach
                </div>
            </div>
        </section>
    @endif
</article>
@endsection
