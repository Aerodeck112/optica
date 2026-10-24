@php
    $poster = $b['poster'];
    if ($poster && $b['eager']) {
        $avif = $poster->variantSets()['avif'] ?? [];
        if ($avif) {
            app('view')->startPush('preload', '<link rel="preload" as="image" type="image/avif" imagesrcset="'.e(collect($avif)->map(fn ($v) => $v['url'].' '.$v['width'].'w')->implode(', ')).'" imagesizes="(max-width: 42rem) 78vw, 34rem" fetchpriority="high">');
        }
    }
@endphp
<section class="hero" data-portal @if ($b['anchor']) id="{{ $b['anchor'] }}" @endif aria-labelledby="hero-title-{{ $blockIndex }}">
    <div class="hero__sky" aria-hidden="true"></div>
    <div class="hero__stars" aria-hidden="true"></div>
    <div class="wrap hero__grid">
        <div class="hero__copy">
            <p class="hero__place"><x-aw-icon name="pin"/>{{ $b['location'] }}</p>
            <h1 class="hero__title" id="hero-title-{{ $blockIndex }}">
                <span>{{ $b['title_1'] }}</span>
                @if ($b['title_2'])<span class="hero__title-2">{{ $b['title_2'] }}</span>@endif
            </h1>
            @if ($b['lede'])<p class="hero__lede">{{ $b['lede'] }}</p>@endif
            <div class="hero__actions">
                @if ($b['primary'])<a class="btn btn--primary" href="{{ $b['primary']['url'] }}" data-track-click="cta_book" data-track-params='{"position":"hero"}'>{{ $b['primary']['label'] }}</a>@endif
                @if ($b['secondary'])<a class="btn btn--ghost" href="{{ $b['secondary']['url'] }}">{{ $b['secondary']['label'] }}</a>@endif
            </div>
            @if ($b['aside'])
                <p class="hero__aside"><x-aw-icon name="cake"/><a href="{{ $b['aside']['url'] }}">{{ $b['aside']['label'] }}</a></p>
            @endif
        </div>

        <div class="portal">
            <div class="portal__glow" aria-hidden="true"></div>
            <div class="portal__rays" aria-hidden="true"></div>
            <div class="portal__frame">
                <div class="portal__window" @if ($b['video']) data-video='{!! aw_json($b['video']) !!}' @endif>
                    @if ($poster)
                        <x-picture :media="$poster" sizes="(max-width: 42rem) 78vw, 34rem" :eager="$b['eager']" class="portal__poster" alt=""/>
                    @endif
                </div>
            </div>
            @if ($b['video'])
                <button type="button" class="portal__control" aria-pressed="false" aria-label="{{ __('site.pause_video') }}" data-label-pause="{{ __('site.pause_video') }}" data-label-play="{{ __('site.play_video') }}">
                    <x-aw-icon name="pause" data-icon="pause"/><x-aw-icon name="play" data-icon="play" hidden/>
                </button>
            @endif
        </div>
    </div>
</section>
