<section class="section promo" @if ($b['anchor']) id="{{ $b['anchor'] }}" @endif aria-labelledby="promo-title-{{ $blockIndex }}">
    <div class="wrap promo__grid">
        <div class="promo__ticket">
            <span class="promo__label">{{ __('site.promo.code_label') }}</span>
            <span class="promo__code" data-promo-code>{{ $b['code'] }}</span>
            <button type="button" class="promo__copy" data-copy-url="{{ $b['code'] }}">
                <x-aw-icon name="copy"/><span>{{ __('site.promo.copy') }}</span>
            </button>
            <span class="promo__copied small" data-copy-status hidden>{{ __('site.promo.copied') }}</span>
        </div>
        <div class="promo__copy-text">
            <h2 id="promo-title-{{ $blockIndex }}" class="promo__title">{{ $b['title'] }}</h2>
            @if ($b['body'])<p class="promo__body">{{ $b['body'] }}</p>@endif
            @if ($b['until'])<p class="promo__until"><x-aw-icon name="calendar"/><span>{{ __('site.promo.valid_until', ['date' => $b['until']]) }}</span></p>@endif
            @if ($b['primary'])
                <a class="btn btn--primary promo__cta" href="{{ $b['primary']['url'] }}" data-track-click="cta_book" data-track-params='{"position":"promo"}'>{{ $b['primary']['label'] }}</a>
            @endif
            @if ($b['terms'])<p class="promo__terms small">{{ $b['terms'] }}</p>@endif
        </div>
    </div>
</section>
