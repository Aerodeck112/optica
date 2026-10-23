@php
    /** @var \App\Modules\Cms\Seo\Seo $seo */
    $locale = app()->getLocale();
    $ogLocale = \App\Support\Locales::SUPPORTED[$locale]['og'] ?? 'ro_RO';
    $ogImage = $seo->image ? url($seo->image) : url(setting('site.default_og_image', '/media/brand/og-default.jpg'));
    $hasMobileBook = ($showMobileBook ?? true) && $bookingOnline;
@endphp
<!doctype html>
<html lang="{{ $locale }}" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $seo->fullTitle() }}</title>
    @if ($seo->description)<meta name="description" content="{{ $seo->description }}">@endif
    <meta name="robots" content="{{ $seo->robots() }}">
    @if (! $seo->noindex && $seo->canonical)<link rel="canonical" href="{{ $seo->canonical }}">@endif
    @if (count($seo->alternates) > 1)
        @foreach ($seo->alternates as $altLocale => $altUrl)
            <link rel="alternate" hreflang="{{ \App\Support\Locales::SUPPORTED[$altLocale]['hreflang'] }}" href="{{ $altUrl }}">
        @endforeach
        @isset($seo->alternates['ro'])<link rel="alternate" hreflang="x-default" href="{{ $seo->alternates['ro'] }}">@endisset
    @endif
    <meta property="og:type" content="{{ $seo->type }}">
    <meta property="og:site_name" content="{{ __('site.brand') }}">
    <meta property="og:title" content="{{ $seo->title }}">
    @if ($seo->description)<meta property="og:description" content="{{ $seo->description }}">@endif
    <meta property="og:url" content="{{ $seo->canonical ?? url()->current() }}">
    <meta property="og:image" content="{{ $ogImage }}">
    @if ($seo->imageAlt)<meta property="og:image:alt" content="{{ $seo->imageAlt }}">@endif
    <meta property="og:locale" content="{{ $ogLocale }}">
    <meta name="twitter:card" content="summary_large_image">
    @if ($verification = filled_setting('site.search_console_verification'))<meta name="google-site-verification" content="{{ $verification }}">@endif
    <meta name="theme-color" content="#05040f">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="/favicon.ico" sizes="48x48">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="preload" href="{{ Vite::asset('resources/fonts/unbounded-aw.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ Vite::asset('resources/fonts/onest-aw.woff2') }}" as="font" type="font/woff2" crossorigin>
    @stack('preload')
    @vite(['resources/css/site.css', 'resources/js/site.js'])
    <script nonce="{{ csp_nonce() }}">
        window.AW = {!! aw_json(['nonce' => csp_nonce(), 'locale' => $locale, 'analytics' => $analyticsConfig, 'needsConsent' => $needsConsent]) !!};
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('consent', 'default', {ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', analytics_storage: 'denied', wait_for_update: 500});
    </script>
    @foreach ($seo->jsonLd as $schema)
        <script type="application/ld+json" nonce="{{ csp_nonce() }}">{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    @endforeach
</head>
<body class="@if ($hasMobileBook) has-mobile-book @endif">
    <a class="skip-link" href="#continut">{{ __('site.skip_to_content') }}</a>

    @if (config('aw.is_staging'))
        <div class="staging-ribbon">Mediu de test — conținutul nu este public</div>
    @endif

    @foreach ($banners as $banner)
        <div class="promo-banner promo-banner--{{ $banner->tone }}" data-banner-id="{{ $banner->id }}-{{ $banner->updated_at?->timestamp }}">
            <div class="wrap promo-banner__inner">
                <p>{{ $banner->getTranslation('text', $locale) }}
                    @if ($banner->link_url && \App\Modules\Cms\Services\LinkResolver::isSafe($banner->link_url))
                        <a class="text-link" href="{{ app(\App\Modules\Cms\Services\LinkResolver::class)->url($banner->link_url) }}">{{ $banner->getTranslation('link_label', $locale) ?: __('site.learn_more') }}</a>
                    @endif
                </p>
                @if ($banner->is_dismissible)
                    <button type="button" data-banner-close aria-label="{{ __('site.close') }}"><x-aw-icon name="close" style="width:1rem;height:1rem"/></button>
                @endif
            </div>
        </div>
    @endforeach

    @include('site.partials.header')

    <main id="continut" tabindex="-1">
        @yield('content')
    </main>

    @include('site.partials.footer')

    @if ($hasMobileBook)
        <div class="mobile-book">
            <a class="btn btn--primary btn--block" href="{{ lroute('booking.show') }}" data-track-click="cta_book" data-track-params='{"position":"mobile_bar"}'>{{ __('site.book_cta') }}</a>
        </div>
    @endif

    @include('site.partials.cookies')

    @if ($aiEnabled)
        <button type="button" class="chat-launch" data-chat-launch data-endpoint="{{ route('api.chat') }}" data-confirm-endpoint="{{ route('api.chat.confirm') }}" data-i18n='{!! aw_json(__('site.chat')) !!}' data-fallback="{{ app(\App\Modules\Cms\Services\LinkResolver::class)->url('page:contact') }}">
            <x-aw-icon name="chat"/><span>{{ __('site.chat.open') }}</span>
        </button>
    @endif

    <dialog class="lightbox" id="lightbox" aria-label="{{ __('site.games.gallery') }}">
        <img src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" alt="">
        <div class="lightbox__bar">
            <button type="button" class="btn btn--small btn--ghost" data-prev aria-label="Anterioara"><x-aw-icon name="chevron-left" style="width:1rem;height:1rem"/></button>
            <span data-caption></span>
            <span class="cluster">
                <button type="button" class="btn btn--small btn--ghost" data-next aria-label="Următoarea"><x-aw-icon name="chevron-right" style="width:1rem;height:1rem"/></button>
                <button type="button" class="btn btn--small btn--ghost" data-close>{{ __('site.close') }}</button>
            </span>
        </div>
    </dialog>
</body>
</html>
