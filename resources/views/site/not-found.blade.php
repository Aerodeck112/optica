@php use App\Support\Ui\SiteUrl; @endphp
<x-layouts.site :title="__('site.not_found.title')" noindex>
    <div class="bg-paper">
        <div class="mx-auto max-w-3xl px-4 py-20 sm:px-6">
            <h1 class="text-3xl font-display font-semibold text-heading sm:text-4xl">{{ __('site.not_found.title') }}</h1>
            <p class="mt-4 text-lg">{{ __('site.not_found.text') }}</p>
            <div class="mt-8 flex flex-wrap gap-3">
                <a href="{{ SiteUrl::path() }}" class="btn-primary">{{ __('site.nav.home') }}</a>
                <a href="{{ SiteUrl::route('booking') }}" class="btn bg-lens text-ink">{{ __('site.book_online') }}</a>
            </div>
        </div>
    </div>
</x-layouts.site>
