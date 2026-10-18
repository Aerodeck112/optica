@php use App\Support\Ui\SiteUrl; @endphp
<x-layouts.site :title="$meta['title']" :description="$meta['description']" :canonical="$meta['canonical']" :alternates="$meta['alternates']">
    <article class="bg-paper">
        <div class="mx-auto max-w-3xl px-4 py-14 sm:px-6">
            <p class="text-sm"><a href="{{ SiteUrl::path('reduceri-actuale') }}" class="btn-link">{{ __('site.nav.offers') }}</a></p>
            <h1 class="mt-3 text-3xl font-light text-heading sm:text-4xl">{{ $offer->text('title') }}</h1>
            @if ($offer->starts_on && $offer->ends_on)<p class="mt-2 text-muted">{{ $offer->starts_on->format('d.m.Y') }} – {{ $offer->ends_on->format('d.m.Y') }}</p>@endif
            @if ($offer->image)<img src="{{ $offer->image->url(1600) }}" alt="{{ $offer->image->altText() }}" class="mt-8 w-full rounded-2xl">@endif
            <div class="mt-8 space-y-4 text-lg leading-relaxed">{{ \App\Domain\Cms\SafeText::render($offer->text('body')) }}</div>
        </div>
    </article>
</x-layouts.site>
