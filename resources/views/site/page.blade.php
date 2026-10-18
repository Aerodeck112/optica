<x-layouts.site :title="$meta['title']" :description="$meta['description']" :canonical="$meta['canonical']" :alternates="$meta['alternates']" :noindex="$meta['noindex']">
    @if ($preview)
        <div class="bg-lens px-4 py-2 text-center text-sm font-semibold text-ink" role="status">{{ __('site.preview_banner') }}</div>
    @endif
    @if ($page->template === 'legal')
        <div class="bg-paper">
            <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
                <h1 class="text-3xl font-light text-heading sm:text-4xl">{{ $content['title'][app()->getLocale()] ?? $content['title']['ro'] }}</h1>
                @if (app()->getLocale() === 'hu')<p class="mt-3 rounded-lg bg-white px-4 py-3 text-sm text-muted ring-1 ring-line">{{ __('site.legal_ro_only') }}</p>@endif
                <div class="prose-site mt-8 space-y-8">
                    @foreach ($content['sections'] as $section)
                        <section>
                            @if ($h = \App\Domain\Cms\SectionView::text($section, 'heading'))<h2 class="text-xl font-semibold text-heading">{{ $h }}</h2>@endif
                            <div class="mt-3 space-y-3 leading-relaxed">{{ \App\Domain\Cms\SafeText::render(\App\Domain\Cms\SectionView::text($section, 'body')) }}</div>
                        </section>
                    @endforeach
                </div>
            </div>
        </div>
    @else
        @foreach ($content['sections'] as $i => $section)
            @includeIf('site.sections.'.$section['type'], ['section' => $section, 'index' => $i, 'first' => $i === 0])
        @endforeach
    @endif
</x-layouts.site>
