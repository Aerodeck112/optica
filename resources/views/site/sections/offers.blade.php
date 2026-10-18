@php use App\Domain\Cms\SectionView as S; use App\Support\Ui\SiteUrl; $offers = $catalog->offers(); @endphp
<section class="bg-paper">
    <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6">
        @if ($first)<h1 class="text-3xl font-display font-semibold text-heading sm:text-4xl">{{ S::text($section, 'heading') }}</h1>@else<h2 class="text-2xl font-display font-semibold text-heading sm:text-3xl">{{ S::text($section, 'heading') }}</h2>@endif
        <div class="mt-4 max-w-2xl text-lg">{{ \App\Domain\Cms\SafeText::render(S::text($section, 'body')) }}</div>
        @if ($offers->isEmpty())
            <p class="mt-8 rounded-2xl bg-white p-6 ring-1 ring-line">{{ __('site.offers.none') }}</p>
        @else
            <ul class="mt-10 grid gap-8 md:grid-cols-2">
                @foreach ($offers as $o)
                    <li class="overflow-hidden rounded-2xl bg-white ring-1 ring-line">
                        @if ($o->image)<img src="{{ $o->image->url(960) }}" alt="{{ $o->image->altText() }}" loading="lazy" class="aspect-[16/9] w-full object-cover">@endif
                        <div class="p-6">
                            <h2 class="text-xl font-semibold text-heading">{{ $o->text('title') }}</h2>
                            @if ($o->ends_on)<p class="mt-1 text-sm text-muted">{{ __('site.offers.valid_until', ['date' => $o->ends_on->format('d.m.Y')]) }}</p>@endif
                            <p class="mt-3">{{ $o->text('summary') }}</p>
                            <a href="{{ SiteUrl::path('reduceri-actuale/'.$o->slug()) }}" class="btn-secondary mt-5">{{ __('site.offers.details') }}</a>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
