{{-- Labelled visual simulation (WEB-04): illustrative only, no diagnosis or precision claim. --}}
@if ($before && $after)
    <figure class="mt-8" x-data="{ pos: 50 }">
        <div class="relative overflow-hidden rounded-2xl">
            <img src="{{ $after->url(1600) }}" alt="{{ $after->altText() }}" loading="lazy" class="block aspect-[16/9] w-full object-cover">
            <img src="{{ $before->url(1600) }}" alt="{{ $before->altText() }}" loading="lazy" class="absolute inset-0 h-full w-full object-cover" :style="`clip-path: inset(0 ${100 - pos}% 0 0)`">
            <div class="pointer-events-none absolute inset-y-0 w-1 -translate-x-1/2 bg-lens" :style="`left: ${pos}%`" aria-hidden="true"></div>
            <span class="absolute left-3 top-3 rounded-full bg-white/90 px-3 py-1 text-xs font-semibold text-heading">{{ __('site.tools.blurred') }}</span>
            <span class="absolute right-3 top-3 rounded-full bg-white/90 px-3 py-1 text-xs font-semibold text-heading">{{ __('site.tools.sharp') }}</span>
        </div>
        <label for="blur-range" class="mt-4 block text-sm font-medium">{{ __('site.tools.compare') }}</label>
        <input id="blur-range" type="range" min="0" max="100" x-model="pos" class="mt-2 w-full accent-[#004975]">
        <figcaption class="mt-2 text-sm text-muted">{{ __('site.tools.simulation_note') }}</figcaption>
    </figure>
@endif
