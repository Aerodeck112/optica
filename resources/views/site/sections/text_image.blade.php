@php use App\Domain\Cms\SectionView as S; $img = S::media($section['data']['image_id'] ?? null); $left = ($section['data']['image_side'] ?? 'right') === 'left'; @endphp
<section class="{{ $index % 2 ? 'bg-white' : 'bg-paper' }}">
    <div class="mx-auto grid max-w-7xl items-center gap-12 px-4 py-16 sm:px-6 lg:grid-cols-2">
        <div @class(['lg:order-2' => $left])>
            @if ($first)<h1 class="text-3xl font-light text-heading sm:text-4xl">{{ S::text($section, 'heading') }}</h1>@else<h2 class="text-2xl font-light text-heading sm:text-3xl">{{ S::text($section, 'heading') }}</h2>@endif
            <div class="mt-5 max-w-prose space-y-4 text-lg leading-relaxed">{{ \App\Domain\Cms\SafeText::render(S::text($section, 'body')) }}</div>
            @if (($link = S::link($section['data']['button_target'] ?? null)) && S::text($section, 'button_label'))
                <a href="{{ $link }}" class="btn-secondary mt-6">{{ S::text($section, 'button_label') }}</a>
            @endif
        </div>
        <x-site.lens-image :media="$img" @class(['mx-auto w-full max-w-lg', 'lg:order-1' => $left]) />
    </div>
</section>
