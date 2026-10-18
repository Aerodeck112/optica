@php use App\Domain\Cms\SectionView as S; @endphp
<section class="{{ $index % 2 ? 'bg-white' : 'bg-paper' }}">
    <div class="mx-auto max-w-3xl px-4 py-14 sm:px-6">
        @if ($h = S::text($section, 'heading'))
            @if ($first)<h1 class="text-3xl font-display font-semibold text-heading sm:text-4xl">{{ $h }}</h1>@else<h2 class="text-2xl font-display font-semibold text-heading sm:text-3xl">{{ $h }}</h2>@endif
        @endif
        <div class="site-text mt-5 space-y-4 text-lg leading-relaxed">{{ \App\Domain\Cms\SafeText::render(S::text($section, 'body')) }}</div>
    </div>
</section>
