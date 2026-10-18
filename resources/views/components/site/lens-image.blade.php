@props(['media', 'sizes' => '(min-width: 1024px) 40vw, 90vw', 'class' => '', 'eager' => false, 'tone' => 'light'])
{{--
    The brand's lens outline as an image frame, with the TABO axis scale (0°–180°) that is printed
    on every spectacle prescription to note the cylinder axis. The site's one recurring visual motif.
--}}
@if ($media)
    @php
        // The photo is an ellipse filling 500 × 400; the scale sits around it in a 600 × 480 box.
        [$cx, $cy, $a, $b] = [300, 240, 250, 200];
        $point = fn (float $deg, float $k) => [round($cx + $a * $k * cos(deg2rad($deg)), 1), round($cy - $b * $k * sin(deg2rad($deg)), 1)];
        $ticks = [];
        for ($deg = 0; $deg <= 180; $deg += 10) {
            $major = $deg % 30 === 0;
            [$x1, $y1] = $point($deg, 1.035);
            [$x2, $y2] = $point($deg, $major ? 1.085 : 1.06);
            $ticks[] = compact('deg', 'major', 'x1', 'y1', 'x2', 'y2') + ['label' => $point($deg, 1.15)];
        }
        $ink = $tone === 'dark' ? 'var(--color-mist-300)' : 'var(--color-ink)';
    @endphp
    <div {{ $attributes->merge(['class' => 'relative '.$class]) }}>
        <svg class="pointer-events-none absolute -inset-[10%] h-[120%] w-[120%]" viewBox="0 0 600 480" aria-hidden="true" focusable="false">
            <ellipse cx="{{ $cx }}" cy="{{ $cy }}" rx="{{ $a * 1.035 }}" ry="{{ $b * 1.035 }}" fill="none" stroke="var(--color-sky)" stroke-opacity=".55" stroke-width="2"/>
            @foreach ($ticks as $t)
                <line x1="{{ $t['x1'] }}" y1="{{ $t['y1'] }}" x2="{{ $t['x2'] }}" y2="{{ $t['y2'] }}" stroke="var(--color-sky)" stroke-width="{{ $t['major'] ? 2.2 : 1.4 }}"/>
                @if ($t['major'])
                    <text x="{{ $t['label'][0] }}" y="{{ $t['label'][1] }}" fill="{{ $ink }}" font-family="Inter Variable, sans-serif" font-size="15" font-weight="500"
                          text-anchor="middle" dominant-baseline="middle">{{ $t['deg'] }}</text>
                @endif
            @endforeach
        </svg>
        <img src="{{ $media->url(960) }}" srcset="{{ $media->srcset() }}" sizes="{{ $sizes }}" alt="{{ $media->altText() }}"
             @if (! $eager) loading="lazy" @endif decoding="async"
             class="relative aspect-[5/4] w-full rounded-[50%] object-cover">
    </div>
@endif
