@php use App\Domain\Cms\SectionView as S; $img = S::media($section['data']['image_id'] ?? null); $phones = \App\Support\Settings::get('site.booking_phones', []); @endphp
<section class="overflow-hidden bg-paper">
    <div class="mx-auto grid max-w-7xl items-center gap-12 px-4 py-14 sm:px-6 lg:grid-cols-[1.1fr_1fr] lg:py-20">
        <div>
            <h1 class="max-w-xl text-4xl font-light leading-[1.1] text-heading sm:text-5xl lg:text-6xl">{{ S::text($section, 'heading') }}</h1>
            <div class="mt-6 max-w-lg text-lg leading-relaxed text-body">{{ \App\Domain\Cms\SafeText::render(S::text($section, 'body')) }}</div>
            <div class="mt-8 flex flex-wrap items-center gap-4">
                @if ($link = S::link($section['data']['button_target'] ?? null))
                    <a href="{{ $link }}" class="btn bg-lens px-6 py-3 text-base text-ink hover:bg-lens-600">{{ S::text($section, 'button_label') }}</a>
                @endif
                @if (($section['data']['show_phones'] ?? false) && $phones)
                    <p class="text-sm text-body">{{ __('site.or_call') }}
                        @foreach ($phones as $p)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $p) }}" class="font-semibold text-ink hover:underline">{{ $p }}</a>@if (! $loop->last) {{ __('site.or') }} @endif @endforeach
                    </p>
                @endif
            </div>
        </div>
        <x-site.lens-image :media="$img" eager class="mx-auto w-full max-w-xl" />
    </div>
</section>
