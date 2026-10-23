@props([
    'media' => null,
    'sizes' => '100vw',
    'eager' => false,
    'alt' => null,
    'class' => '',
    'focal' => null,
])
@php
    /** @var \App\Modules\Cms\Models\Media|null $media */
    $sets = $media?->variantSets() ?? [];
    $fallbackSet = $sets['jpg'] ?? $sets['webp'] ?? [];
    $largestSet = $sets['webp'] ?? $fallbackSet;
    $largest = $largestSet ? $largestSet[array_key_last($largestSet)] : null;
    $fallback = $fallbackSet ? $fallbackSet[array_key_last($fallbackSet)] : null;
    $srcset = fn ($set) => collect($set)->map(fn ($v) => $v['url'].' '.$v['width'].'w')->implode(', ');
    $altText = $alt ?? $media?->altText() ?? '';
    $position = $focal ?? ($media ? round($media->focal_x * 100).'% '.round($media->focal_y * 100).'%' : '50% 50%');
@endphp
@if ($media && $largest)
<div {{ $attributes->merge(['class' => trim('pic '.$class)]) }} style="--focal: {{ $position }}; --pic-bg: {{ $media->dominant_color ?? '#0a1033' }}">
    <picture>
        @isset($sets['avif'])<source type="image/avif" srcset="{{ $srcset($sets['avif']) }}" sizes="{{ $sizes }}">@endisset
        @isset($sets['webp'])<source type="image/webp" srcset="{{ $srcset($sets['webp']) }}" sizes="{{ $sizes }}">@endisset
        <img src="{{ ($fallback ?? $largest)['url'] }}"
             @if ($fallbackSet) srcset="{{ $srcset($fallbackSet) }}" sizes="{{ $sizes }}" @endif
             width="{{ $largest['width'] }}" height="{{ $largest['height'] }}"
             alt="{{ $altText }}"
             @if ($eager) fetchpriority="high" loading="eager" @else loading="lazy" @endif
             decoding="async">
    </picture>
</div>
@endif
