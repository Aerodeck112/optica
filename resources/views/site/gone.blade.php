<x-layouts.site :title="__('site.not_found.gone')" noindex>
    <div class="bg-paper"><div class="mx-auto max-w-3xl px-4 py-20 sm:px-6">
        <h1 class="text-3xl font-light text-heading">{{ __('site.not_found.gone') }}</h1>
        <p class="mt-4"><a href="{{ \App\Support\Ui\SiteUrl::path() }}" class="btn-link">{{ __('site.nav.home') }}</a></p>
    </div></div>
</x-layouts.site>
