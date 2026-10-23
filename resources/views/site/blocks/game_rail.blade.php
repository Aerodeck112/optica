<section class="section" @if ($b['anchor']) id="{{ $b['anchor'] }}" @endif aria-labelledby="rail-{{ $blockIndex }}">
    <div class="wrap">
        <div class="rail-head">
            <div class="section-head" style="margin-bottom:0">
                <h2 id="rail-{{ $blockIndex }}">{{ $b['heading'] }}</h2>
                @if ($b['intro'])<p>{{ $b['intro'] }}</p>@endif
            </div>
            @if ($b['cta'])<a class="btn btn--ghost" href="{{ $b['cta']['url'] }}">{{ $b['cta']['label'] }}</a>@endif
        </div>
        <div class="rail" role="list">
            @foreach ($b['games'] as $game)
                <div role="listitem" style="display:contents">@include('site.games.card', ['game' => $game])</div>
            @endforeach
        </div>
        <p class="source-note">{{ __('site.game_art_note') }}</p>
    </div>
</section>
