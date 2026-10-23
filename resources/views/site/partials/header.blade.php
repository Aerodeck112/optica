@php
    $current = url()->current();
    $alternates = isset($seo) ? $seo->alternates : [];
@endphp
<header class="site-header" data-open="false">
    <div class="wrap site-header__inner">
        <a class="site-logo" href="{{ lroute('page') }}" aria-label="{{ __('site.brand') }} — {{ __('site.location') }}">
            <img src="/media/brand/aw-logo-horizontal.svg" alt="Another World" width="168" height="34">
        </a>

        <nav class="site-nav" id="site-nav" aria-label="{{ __('site.main_navigation') }}">
            <ul class="site-nav__list" role="list">
                @foreach ($menus['header'] ?? [] as $item)
                    @continue($item['style'] === 'button')
                    <li><a class="site-nav__link" href="{{ $item['url'] }}" @if (rtrim($item['url'], '/') === rtrim($current, '/')) aria-current="page" @endif @if ($item['new_tab']) target="_blank" rel="noopener" @endif>{{ $item['label'] }}</a></li>
                @endforeach
            </ul>
            <div class="mobile-menu-extra">
                @if ($bookingOnline)
                    <a class="btn btn--primary btn--block" href="{{ lroute('booking.show') }}">{{ __('site.book_cta') }}</a>
                @endif
                @if (count($alternates) > 1)
                    <div class="lang-switch" aria-label="{{ __('site.language') }}">
                        @foreach ($alternates as $code => $url)
                            <a href="{{ $url }}" lang="{{ $code }}" hreflang="{{ $code }}" @if ($code === app()->getLocale()) aria-current="true" @endif>{{ strtoupper($code) }}</a>
                        @endforeach
                    </div>
                @endif
            </div>
        </nav>

        @if (count($alternates) > 1)
            <div class="lang-switch" aria-label="{{ __('site.language') }}">
                @foreach ($alternates as $code => $url)
                    <a href="{{ $url }}" lang="{{ $code }}" hreflang="{{ $code }}" @if ($code === app()->getLocale()) aria-current="true" @endif>{{ strtoupper($code) }}</a>
                @endforeach
            </div>
        @endif

        @if ($bookingOnline)
            <a class="btn btn--primary btn--small site-header__cta" href="{{ lroute('booking.show') }}" data-track-click="cta_book" data-track-params='{"position":"header"}'>{{ __('site.book_cta') }}</a>
        @endif

        <button type="button" class="nav-toggle" aria-controls="site-nav" aria-expanded="false" aria-label="{{ __('site.menu_open') }}" data-label-open="{{ __('site.menu_open') }}" data-label-close="{{ __('site.menu_close') }}">
            <span class="nav-toggle__bars"></span>
        </button>
    </div>
</header>
