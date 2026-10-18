@php use App\Domain\Cms\SectionView as S; @endphp
<section class="{{ $index % 2 ? 'bg-white' : 'bg-paper' }}">
    <div class="mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:px-6 lg:grid-cols-[1fr_1.4fr]">
        <div>
            <h2 class="text-2xl font-light text-heading sm:text-3xl">{{ S::text($section, 'heading') }}</h2>
            <div class="mt-4 leading-relaxed">{{ \App\Domain\Cms\SafeText::render(S::text($section, 'body')) }}</div>
        </div>
        <livewire:site.lead-form :type="$section['data']['lead_type'] ?? 'contact'" :key="'lead-'.$section['id']" />
    </div>
</section>
