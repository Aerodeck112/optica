<section class="section cta-portal" @if ($b['anchor']) id="{{ $b['anchor'] }}" @endif>
    <div class="cta-portal__ring" aria-hidden="true"></div>
    <div class="wrap">
        <h2>{{ $b['heading'] }}</h2>
        @if ($b['text'])<p>{{ $b['text'] }}</p>@endif
        <div class="cluster">
            @if ($b['primary'])<a class="btn btn--primary" href="{{ $b['primary']['url'] }}" data-track-click="cta_book" data-track-params='{"position":"cta_portal"}'>{{ $b['primary']['label'] }}</a>@endif
            @if ($b['secondary'])<a class="btn btn--ghost" href="{{ $b['secondary']['url'] }}">{{ $b['secondary']['label'] }}</a>@endif
        </div>
    </div>
</section>
