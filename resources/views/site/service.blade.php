@php use App\Support\Ui\SiteUrl; use App\Domain\Cms\SectionView as S; $img = S::media($service->image_id); @endphp
<x-layouts.site :title="$meta['title']" :description="$meta['description']" :canonical="$meta['canonical']" :alternates="$meta['alternates']">
    <article class="bg-paper">
        <div class="mx-auto grid max-w-7xl items-center gap-12 px-4 py-14 sm:px-6 lg:grid-cols-2">
            <div>
                <p class="text-sm"><a href="{{ SiteUrl::path('servicii') }}" class="btn-link">{{ __('site.nav.services') }}</a></p>
                <h1 class="mt-3 text-3xl font-light text-heading sm:text-4xl">{{ $service->publicValue('name') }}</h1>
                <div class="mt-5 space-y-4 text-lg leading-relaxed">{{ \App\Domain\Cms\SafeText::render($service->publicValue('description')) }}</div>
                @if ($service->publicValue('preparation'))
                    <div class="mt-6 rounded-xl bg-white p-4 ring-1 ring-line">
                        <h2 class="font-semibold text-heading">{{ __('site.service.preparation') }}</h2>
                        <div class="mt-2">{{ \App\Domain\Cms\SafeText::render($service->publicValue('preparation')) }}</div>
                    </div>
                @endif
                <a href="{{ SiteUrl::route('booking') }}" class="btn mt-8 bg-lens px-6 py-3 text-ink hover:bg-lens-600">{{ __('site.book_online') }}</a>
            </div>
            <x-site.lens-image :media="$img" eager class="mx-auto w-full max-w-lg" />
        </div>
    </article>
</x-layouts.site>
