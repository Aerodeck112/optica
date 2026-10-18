@php use App\Domain\Cms\SectionView as S; $images = collect($section['data']['image_ids'] ?? [])->map(fn ($id) => S::media($id))->filter(); @endphp
@if ($images->isNotEmpty())
<section class="{{ $index % 2 ? 'bg-white' : 'bg-paper' }}">
    <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6">
        @if ($h = S::text($section, 'heading'))<h2 class="mb-6 text-2xl font-display font-semibold text-heading">{{ $h }}</h2>@endif
        <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($images as $m)
                <li><img src="{{ $m->url(960) }}" srcset="{{ $m->srcset() }}" sizes="(min-width: 1024px) 33vw, 90vw" alt="{{ $m->altText() }}" loading="lazy" class="aspect-[4/3] w-full rounded-2xl object-cover"></li>
            @endforeach
        </ul>
    </div>
</section>
@endif
