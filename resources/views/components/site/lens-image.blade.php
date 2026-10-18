@props(['media', 'sizes' => '(min-width: 1024px) 40vw, 90vw', 'class' => '', 'eager' => false])
{{-- The brand's lens outline used as an image frame: the site's one recurring visual motif. --}}
@if ($media)
    <div {{ $attributes->merge(['class' => 'relative '.$class]) }}>
        <div class="absolute -inset-3 rounded-[50%] border-2 border-sky/40" aria-hidden="true"></div>
        <img src="{{ $media->url(960) }}" srcset="{{ $media->srcset() }}" sizes="{{ $sizes }}" alt="{{ $media->altText() }}"
             @if (! $eager) loading="lazy" @endif decoding="async"
             class="relative aspect-[5/4] w-full rounded-[50%] object-cover">
    </div>
@endif
