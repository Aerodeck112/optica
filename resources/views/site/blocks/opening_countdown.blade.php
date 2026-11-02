<section class="section opening @if ($b['is_today']) is-today @endif" @if ($b['anchor']) id="{{ $b['anchor'] }}" @endif aria-labelledby="opening-title-{{ $blockIndex }}">
    <div class="wrap opening__grid">
        <div class="opening__date" aria-hidden="true">
            <span class="opening__orbit"></span>
            <span class="opening__weekday">{{ $b['weekday'] }}</span>
            <span class="opening__day">{{ $b['day'] }}</span>
            <span class="opening__month">{{ $b['month'] }}</span>
        </div>
        <div class="opening__copy">
            <h2 id="opening-title-{{ $blockIndex }}" class="opening__title">
                <span data-opening-before @if ($b['is_today'] && $b['today_title']) hidden @endif>{{ $b['title_1'] }}@if ($b['title_2'])<span class="opening__title-2">{{ $b['title_2'] }}</span>@endif</span>
                @if ($b['today_title'])<span data-opening-today @unless ($b['is_today']) hidden @endunless>{{ $b['today_title'] }}</span>@endif
            </h2>
            <p class="opening__when"><x-aw-icon name="calendar"/><span>{{ __('site.opening.when', ['date' => $b['date_label']]) }}</span></p>
            @if ($b['body'])<p class="opening__body">{{ $b['body'] }}</p>@endif
            @if ($b['remaining'] > 0)
                @php
                    $left = $b['remaining'];
                    $units = ['days' => intdiv($left, 86400), 'hours' => intdiv($left % 86400, 3600), 'minutes' => intdiv($left % 3600, 60), 'seconds' => $left % 60];
                @endphp
                <div class="countdown" role="timer" aria-live="off" aria-label="{{ __('site.opening.countdown') }}" data-countdown="{{ $b['target'] }}">
                    @foreach ($units as $unit => $value)
                        <div class="countdown__cell">
                            <span class="countdown__value" data-unit="{{ $unit }}">{{ $unit === 'days' ? $value : str_pad((string) $value, 2, '0', STR_PAD_LEFT) }}</span>
                            <span class="countdown__label">{{ __('site.opening.units.'.$unit) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
            @if ($b['primary'] || $b['secondary'])
                <div class="cluster opening__actions">
                    @if ($b['primary'])<a class="btn btn--primary" href="{{ $b['primary']['url'] }}" data-track-click="cta_book" data-track-params='{"position":"opening"}'>{{ $b['primary']['label'] }}</a>@endif
                    @if ($b['secondary'])<a class="btn btn--ghost" href="{{ $b['secondary']['url'] }}">{{ $b['secondary']['label'] }}</a>@endif
                </div>
            @endif
        </div>
    </div>
</section>
