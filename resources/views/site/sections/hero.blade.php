@php use App\Domain\Cms\SectionView as S; $img = S::media($section['data']['image_id'] ?? null); $phones = \App\Support\Settings::get('site.booking_phones', []); @endphp
<section class="relative isolate overflow-hidden bg-ink-900 text-white">
    {{-- Parallel light enters, is bent at the lens plane and meets in one point: the photo of the shop, framed as a lens. --}}
    <svg class="pointer-events-none absolute inset-0 -z-10 hidden h-full w-full lg:block" viewBox="0 0 1440 720" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">
        <g fill="none" stroke="var(--color-sky)" stroke-linecap="round" stroke-width="1.3">
            @foreach ([170, 245, 320, 395, 470, 545] as $i => $y)
                <path stroke-opacity="{{ 0.3 + ($i % 3) * 0.08 }}" d="M0 {{ $y }} L760 {{ $y }} L1060 350"/>
            @endforeach
        </g>
        <circle cx="1060" cy="350" r="5" fill="var(--color-lens)"/>
        <path d="M760 110 C730 230 730 470 760 590 C790 470 790 230 760 110 Z" fill="none" stroke="var(--color-sky)" stroke-opacity=".28" stroke-width="1.5"/>
    </svg>

    <div class="mx-auto grid max-w-7xl items-center gap-14 px-4 pb-12 pt-14 sm:px-6 lg:grid-cols-[1.05fr_1fr] lg:pb-16 lg:pt-20">
        <div>
            <h1 class="animate-focus-in font-display max-w-xl text-4xl font-semibold leading-[1.05] text-white sm:text-5xl lg:text-6xl">{{ S::text($section, 'heading') }}</h1>
            <div class="mt-6 max-w-lg text-lg leading-relaxed text-mist-300">{{ \App\Domain\Cms\SafeText::render(S::text($section, 'body')) }}</div>
            <div class="mt-9 flex flex-wrap items-center gap-x-5 gap-y-3">
                @if ($link = S::link($section['data']['button_target'] ?? null))
                    <a href="{{ $link }}" class="btn bg-lens px-6 py-3 text-base text-ink hover:bg-lens-600">{{ S::text($section, 'button_label') }}</a>
                @endif
                @if (($section['data']['show_phones'] ?? false) && $phones)
                    <p class="text-sm text-mist-300">{{ __('site.or_call') }}
                        @foreach ($phones as $p)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $p) }}" class="font-semibold whitespace-nowrap text-white underline decoration-lens decoration-2 underline-offset-4 hover:text-lens">{{ $p }}</a>@if (! $loop->last) {{ __('site.or') }} @endif @endforeach
                    </p>
                @endif
            </div>
        </div>
        <x-site.lens-image :media="$img" eager tone="dark" class="mx-auto w-[86%] max-w-lg lg:w-full" />
    </div>

    @if ($first)
        {{-- Figures confirmed by Optica Optofarm (DECISIONS.md, N-02). --}}
        <dl class="mx-auto grid max-w-7xl grid-cols-2 gap-px border-t border-white/15 px-4 sm:px-6 lg:grid-cols-4">
            @foreach (['founded', 'locations', 'specialists', 'patients'] as $key)
                <div class="flex flex-col gap-1 py-5 sm:flex-row sm:items-baseline sm:gap-3 lg:py-6">
                    <dt class="order-2 text-sm leading-snug text-mist-300">{{ __('site.facts.'.$key) }}</dt>
                    <dd class="order-1 font-display text-2xl font-semibold text-lens sm:text-3xl">{{ __('site.facts.values.'.$key) }}</dd>
                </div>
            @endforeach
        </dl>
    @endif
</section>
