@php use App\Domain\Cms\SectionView as S; @endphp
<section class="bg-ink text-white">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-6 px-4 py-14 sm:px-6">
        <div class="max-w-2xl">
            <h2 class="text-2xl font-light sm:text-3xl">{{ S::text($section, 'heading') }}</h2>
            @if ($b = S::text($section, 'body'))<p class="mt-2 text-mist-300">{{ $b }}</p>@endif
        </div>
        @if ($link = S::link($section['data']['button_target'] ?? null))
            <a href="{{ $link }}" class="btn bg-lens px-6 py-3 text-base text-ink hover:bg-lens-600">{{ S::text($section, 'button_label') }}</a>
        @endif
    </div>
</section>
