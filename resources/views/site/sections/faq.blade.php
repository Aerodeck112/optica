@php use App\Domain\Cms\SectionView as S; $items = S::text($section, 'items') ?: []; @endphp
<section class="{{ $index % 2 ? 'bg-white' : 'bg-paper' }}">
    <div class="mx-auto max-w-3xl px-4 py-16 sm:px-6">
        <h2 class="text-2xl font-light text-heading sm:text-3xl">{{ S::text($section, 'heading') }}</h2>
        <div class="mt-8 divide-y divide-line border-y border-line">
            @foreach ($items as $item)
                <details class="group py-4">
                    <summary class="flex min-h-11 cursor-pointer items-center justify-between gap-4 text-lg font-medium text-heading">{{ $item['q'] ?? '' }}<span class="text-ink transition-transform group-open:rotate-45" aria-hidden="true">+</span></summary>
                    <div class="mt-3 space-y-3 leading-relaxed">{{ \App\Domain\Cms\SafeText::render($item['a'] ?? '') }}</div>
                </details>
            @endforeach
        </div>
    </div>
</section>
