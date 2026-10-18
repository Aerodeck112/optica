@php use App\Domain\Cms\SectionView as S; @endphp
<section class="relative overflow-hidden bg-ink-900 text-white">
    <div class="mx-auto grid max-w-7xl items-center gap-8 px-4 py-14 sm:px-6 lg:grid-cols-[1fr_auto] lg:py-16">
        <div class="max-w-2xl">
            <h2 class="font-display text-2xl font-semibold text-white sm:text-3xl">{{ S::text($section, 'heading') }}</h2>
            @if ($b = S::text($section, 'body'))<p class="mt-2 text-mist-300">{{ $b }}</p>@endif
            @if ($link = S::link($section['data']['button_target'] ?? null))
                <a href="{{ $link }}" class="btn mt-7 bg-lens px-6 py-3 text-base text-ink hover:bg-lens-600">{{ S::text($section, 'button_label') }}</a>
            @endif
        </div>
        {{-- A line of an eye chart, the last thing a patient reads before the exam ends. --}}
        <x-site.optotype-row class="h-10 w-auto opacity-90 sm:h-12" />
    </div>
</section>
