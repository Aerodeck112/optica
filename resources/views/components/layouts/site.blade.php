@props(['title' => null, 'description' => null, 'canonical' => null, 'noindex' => false, 'alternates' => null, 'ogImage' => null])
@php
    use App\Support\Settings;
    use App\Support\Ui\SiteUrl;
    $locale = app()->getLocale();
    $phones = Settings::get('site.booking_phones', []);
    $footerLocations = \App\Models\Location::query()->where('is_active', true)->where('is_public', true)->whereNotNull('published_at')->orderBy('sort_order')->get();
    // Menus are edited in Admin → Meniuri; the list below is only the fallback before content exists.
    $menu = fn (string $name) => \App\Models\MenuItem::with('page')->where('menu', $name)->where('is_active', true)->orderBy('sort_order')->get()
        ->map(fn ($i) => [$i->path(), $i->label(), $i->url()])->all();
    $nav = $menu('header') ?: collect([
        ['servicii', __('site.nav.services')], ['medici', __('site.nav.doctors')], ['puncte-lucru', __('site.nav.locations')],
        ['fabrica-lentile', __('site.nav.factory')], ['reduceri-actuale', __('site.nav.offers')], ['contact', __('site.nav.contact')],
    ])->map(fn ($n) => [$n[0], $n[1], SiteUrl::path($n[0])])->all();
    $legalMenu = $menu('footer');
    $fullTitle = $title ? $title.' – Optica Optofarm' : 'Optica Optofarm – '.__('site.tagline');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $fullTitle }}</title>
    @if ($description)<meta name="description" content="{{ $description }}">@endif
    @if ($noindex)<meta name="robots" content="noindex, nofollow">@endif
    <link rel="canonical" href="{{ $canonical ?? url()->current() }}">
    @foreach (($alternates ?? ['ro' => SiteUrl::switchTo('ro'), 'hu' => SiteUrl::switchTo('hu')]) as $alt => $href)
        <link rel="alternate" hreflang="{{ $alt }}" href="{{ $href }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ ($alternates ?? [])['ro'] ?? SiteUrl::switchTo('ro') }}">
    <meta property="og:title" content="{{ $fullTitle }}">
    @if ($description)<meta property="og:description" content="{{ $description }}">@endif
    <meta property="og:locale" content="{{ $locale === 'hu' ? 'hu_HU' : 'ro_RO' }}">
    <meta property="og:image" content="{{ $ogImage ?? asset('images/logo-optofarm.png') }}">
    <link rel="icon" href="{{ asset('images/logo-optofarm.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    {{ $head ?? '' }}
</head>
<body class="flex min-h-dvh flex-col bg-white" x-data="{ menu: false }" @keydown.escape.window="menu = false">
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-lg focus:bg-lens focus:px-4 focus:py-2 focus:text-ink">{{ __('crm.skip_to_content') }}</a>

<header class="sticky top-0 z-30 border-b border-line bg-white/95 backdrop-blur">
    <div class="mx-auto flex max-w-7xl items-center gap-4 px-4 py-3 sm:px-6">
        <a href="{{ SiteUrl::path() }}" class="flex shrink-0 items-center gap-3" aria-label="Optica Optofarm – {{ __('site.nav.home') }}">
            <img src="{{ asset('images/logo-optofarm.png') }}" alt="" class="h-12 w-12">
            <span class="hidden text-sm leading-tight sm:block"><span class="block font-semibold text-ink">Optica Optofarm</span><span class="text-muted">{{ __('site.tagline_short') }}</span></span>
        </a>
        <nav class="ml-6 hidden flex-1 lg:block" aria-label="{{ __('site.nav.label') }}">
            <ul class="flex gap-1">
                @foreach ($nav as [$slug, $label, $href])
                    @php($active = $slug !== null && trim(preg_replace('#^hu/?#', '', request()->path()), '/') === $slug)
                    <li><a href="{{ $href }}" @if ($active) aria-current="page" @endif
                           @class(['inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-medium', 'bg-mist text-ink' => $active, 'text-heading hover:bg-paper' => ! $active])>{{ $label }}</a></li>
                @endforeach
            </ul>
        </nav>
        <div class="ml-auto flex items-center gap-2">
            <nav class="flex text-sm font-semibold" aria-label="{{ __('crm.language') }}">
                @foreach (['ro' => 'RO', 'hu' => 'HU'] as $l => $label)
                    <a href="{{ SiteUrl::switchTo($l) }}" lang="{{ $l }}" hreflang="{{ $l }}" @if ($l === $locale) aria-current="true" @endif
                       @class(['inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg', 'text-ink underline decoration-lens decoration-[3px] underline-offset-[6px]' => $l === $locale, 'text-muted hover:text-ink' => $l !== $locale])>{{ $label }}</a>
                @endforeach
            </nav>
            <a href="{{ SiteUrl::route('booking') }}" class="btn hidden bg-lens text-ink hover:bg-lens-600 sm:inline-flex">{{ __('site.book_online') }}</a>
            <button type="button" class="btn-secondary px-3 lg:hidden" @click="menu = !menu" :aria-expanded="menu" aria-controls="site-menu">
                <span class="sr-only">{{ __('crm.nav.open') }}</span>
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 5h14v1.5H3zM3 9.25h14v1.5H3zM3 13.5h14V15H3z"/></svg>
            </button>
        </div>
    </div>
    <nav id="site-menu" x-show="menu" x-cloak class="border-t border-line bg-white lg:hidden" aria-label="{{ __('site.nav.label') }}">
        <ul class="mx-auto max-w-7xl px-4 py-2">
            @foreach ($nav as [$slug, $label, $href])
                <li><a href="{{ $href }}" class="flex min-h-12 items-center border-b border-line/60 text-base font-medium text-heading">{{ $label }}</a></li>
            @endforeach
            <li class="py-3"><a href="{{ SiteUrl::route('booking') }}" class="btn w-full bg-lens text-ink">{{ __('site.book_online') }}</a></li>
        </ul>
    </nav>
</header>

<main id="main" class="flex-1">
    {{ $slot }}
</main>

<footer class="bg-ink text-mist-300">
    <div class="mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:px-6 md:grid-cols-[1.4fr_1fr_1fr]">
        <section aria-labelledby="footer-locations">
            <h2 id="footer-locations" class="text-lg font-light text-white">{{ __('site.footer.locations') }}</h2>
            <ul class="mt-4 grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
                @foreach ($footerLocations as $l)
                    <li>
                        <span class="block text-white">{{ $l->publicValue('city') ?? $l->city }}</span>
                        <span class="block">{{ $l->publicValue('address') ?? $l->address }}</span>
                        @if ($l->phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $l->phone) }}" class="text-lens hover:underline">{{ $l->phone }}</a>@endif
                    </li>
                @endforeach
            </ul>
        </section>
        <section aria-labelledby="footer-book">
            <h2 id="footer-book" class="text-lg font-light text-white">{{ __('site.footer.booking') }}</h2>
            <ul class="mt-4 space-y-2 text-sm">
                @foreach ($phones as $phone)
                    <li><a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}" class="text-lens hover:underline">{{ $phone }}</a></li>
                @endforeach
                <li><a href="{{ SiteUrl::route('booking') }}" class="text-white underline underline-offset-4 hover:text-lens">{{ __('site.book_online') }}</a></li>
            </ul>
            <h2 class="mt-8 text-lg font-light text-white">{{ __('site.footer.pages') }}</h2>
            <ul class="mt-4 space-y-2 text-sm">
                @foreach ($nav as [$slug, $label, $href])<li><a href="{{ $href }}" class="hover:text-white">{{ $label }}</a></li>@endforeach
            </ul>
        </section>
        <section aria-labelledby="footer-legal">
            <h2 id="footer-legal" class="text-lg font-light text-white">{{ __('site.footer.legal') }}</h2>
            <ul class="mt-4 space-y-2 text-sm">
                @forelse ($legalMenu as [$slug, $label, $href])
                    <li><a href="{{ $href }}" class="hover:text-white">{{ $label }}</a></li>
                @empty
                    <li><a href="{{ SiteUrl::path('privacy-policy') }}" class="hover:text-white">{{ __('site.footer.privacy') }}</a></li>
                    <li><a href="{{ SiteUrl::path('politica-de-cookies') }}" class="hover:text-white">{{ __('site.footer.cookies') }}</a></li>
                @endforelse
                <li><a href="https://anpc.ro/ce-este-sal/" rel="noopener" class="hover:text-white">ANPC – SAL</a></li>
                <li><a href="https://ec.europa.eu/consumers/odr" rel="noopener" class="hover:text-white">ANPC – SOL</a></li>
            </ul>
        </section>
    </div>
    <div class="border-t border-white/10">
        <p class="mx-auto max-w-7xl px-4 py-5 text-xs sm:px-6">© {{ now()->year }} Optica Optofarm</p>
    </div>
</footer>
<x-translation-mode offer />
@livewireScripts
</body>
</html>
