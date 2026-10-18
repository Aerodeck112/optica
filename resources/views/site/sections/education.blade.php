@php use App\Domain\Cms\SectionView as S; $tool = $section['data']['tool'] ?? ''; @endphp
<section class="{{ $index % 2 ? 'bg-white' : 'bg-paper' }}">
    <div class="mx-auto max-w-5xl px-4 py-16 sm:px-6">
        <h2 class="text-2xl font-light text-heading sm:text-3xl">{{ S::text($section, 'heading') }}</h2>
        <div class="mt-3 max-w-prose leading-relaxed">{{ \App\Domain\Cms\SafeText::render(S::text($section, 'body')) }}</div>
        @if ($tool === 'blur_compare')
            @include('site.tools.blur-compare', ['before' => S::media($section['data']['image_ids'][0] ?? null), 'after' => S::media($section['data']['image_ids'][1] ?? null)])
        @elseif ($tool === 'service_guide')
            @include('site.tools.service-guide')
        @endif
    </div>
</section>
