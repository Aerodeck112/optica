@props(['tone' => 'light'])
{{-- One line of an eye chart: Landolt rings getting smaller, each gap turned a different way. Decorative. --}}
@php
    $rings = [[44, 0], [36, 90], [29, 180], [23, 270], [18, 45], [14, 135], [11, 225]];
    $x = 0;
@endphp
<svg {{ $attributes->merge(['class' => 'h-12 w-auto']) }} viewBox="0 0 250 48" aria-hidden="true" focusable="false">
    @foreach ($rings as [$size, $angle])
        @php($r = $size / 2 - $size / 10)
        @php($cx = $x + $size / 2)
        <g transform="translate({{ $cx }} {{ 48 - $size / 2 }}) rotate({{ $angle }})">
            <path d="M {{ $r }} {{ -$size / 10 }} A {{ $r }} {{ $r }} 0 1 0 {{ $r }} {{ $size / 10 }}" fill="none"
                  stroke="{{ $tone === 'light' ? 'var(--color-lens)' : 'var(--color-ink)' }}" stroke-width="{{ $size / 5 }}"/>
        </g>
        @php($x += $size + 8)
    @endforeach
</svg>
