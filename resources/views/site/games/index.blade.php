@extends('site.layout')

@section('content')
<header class="page-hero">
    <div class="wrap">
        <nav aria-label="Breadcrumb"><ol class="breadcrumbs"><li><a href="{{ url('/') }}">{{ __('site.brand') }}</a></li><li><span aria-current="page">{{ __('site.games.title') }}</span></li></ol></nav>
        <h1>{{ __('site.games.title') }}</h1>
        <p>{{ __('site.games.intro') }}</p>
    </div>
</header>

<section class="section section--tight">
    <div class="wrap">
        @if ($totalVisible === 0)
            <div class="notice">
                <x-aw-icon name="info"/>
                <div class="stack">
                    <p>{{ __('site.games.empty') }}</p>
                    <p><a class="btn btn--primary" href="{{ app(\App\Modules\Cms\Services\LinkResolver::class)->url('page:contact') ?? lroute('booking.show') }}">{{ __('site.contact.form_title') }}</a></p>
                </div>
            </div>
        @else
            @if ($genres->count() > 1 || $hasAges || $hasPlayers)
                <form class="filters" method="get" action="{{ lroute('games.index') }}" role="search" aria-label="{{ __('site.games.filter_apply') }}">
                    @if ($genres->count() > 1)
                        <div class="field">
                            <label class="field__label" for="f-gen">{{ __('site.games.filter_genre') }}</label>
                            <select class="select" id="f-gen" name="gen">
                                <option value="">{{ __('site.games.filter_all') }}</option>
                                @foreach ($genres as $genre)
                                    <option value="{{ $genre->slug }}" @selected(($filters['gen'] ?? null) === $genre->slug)>{{ $genre->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    @if ($hasAges)
                        <div class="field">
                            <label class="field__label" for="f-age">{{ __('site.games.filter_age') }}</label>
                            <select class="select" id="f-age" name="varsta">
                                <option value="">{{ __('site.games.filter_all') }}</option>
                                @foreach ([6, 8, 10, 12, 14, 16, 18] as $age)
                                    <option value="{{ $age }}" @selected((int) ($filters['varsta'] ?? 0) === $age)>{{ __('site.games.age_suitable', ['age' => $age]) }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    @if ($hasPlayers)
                        <div class="field">
                            <label class="field__label" for="f-players">{{ __('site.games.filter_players') }}</label>
                            <input class="input" id="f-players" type="number" name="jucatori" min="1" max="100" inputmode="numeric" value="{{ $filters['jucatori'] ?? '' }}">
                        </div>
                    @endif
                    <div class="cluster">
                        <button class="btn btn--primary" type="submit">{{ __('site.games.filter_apply') }}</button>
                        @if (array_filter($filters))<a class="text-link" href="{{ lroute('games.index') }}">{{ __('site.games.filter_reset') }}</a>@endif
                    </div>
                </form>
            @endif

            <p class="visually-hidden" role="status">{{ $games->count() }}</p>
            @if ($games->isEmpty())
                <p class="muted">{{ __('site.games.no_results') }}</p>
            @else
                <div class="game-grid">
                    @foreach ($games as $game)
                        @include('site.games.card', ['game' => $game])
                    @endforeach
                </div>
                <p class="source-note">{{ __('site.game_art_note') }}. {{ __('site.games.duration_note') }}</p>
            @endif
        @endif
    </div>
</section>
@endsection
