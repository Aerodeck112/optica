@php($record = $getRecord())
@if ($record && $record->isImage())
<div x-data="{
        x: $wire.$entangle('data.focal_x'),
        y: $wire.$entangle('data.focal_y'),
        pick(e) { const r = e.currentTarget.getBoundingClientRect(); this.x = Math.round(((e.clientX - r.left) / r.width) * 100) / 100; this.y = Math.round(((e.clientY - r.top) / r.height) * 100) / 100; },
        key(e) { const s = 0.05; if (e.key === 'ArrowLeft') this.x = Math.max(0, +(this.x - s).toFixed(2)); if (e.key === 'ArrowRight') this.x = Math.min(1, +(+this.x + s).toFixed(2)); if (e.key === 'ArrowUp') this.y = Math.max(0, +(this.y - s).toFixed(2)); if (e.key === 'ArrowDown') this.y = Math.min(1, +(+this.y + s).toFixed(2)); }
    }" class="aw-focal">
    <div class="aw-focal__stage" x-on:click="pick($event)" x-on:keydown="key($event)" tabindex="0" role="application" aria-label="Alege punctul focal. Săgețile mută punctul.">
        <img src="{{ $record->urlForWidth(1200, ['webp', 'jpg']) }}" alt="">
        <span class="aw-focal__dot" x-bind:style="`left: ${x * 100}%; top: ${y * 100}%`"></span>
    </div>
    <div class="aw-focal__crops" aria-hidden="true">
        <div><span class="aw-muted">Card 4:5</span><div class="aw-focal__crop" style="aspect-ratio: 4/5"><img src="{{ $record->urlForWidth(800, ['webp', 'jpg']) }}" alt="" x-bind:style="`object-position: ${x * 100}% ${y * 100}%`"></div></div>
        <div><span class="aw-muted">Bandă 21:9</span><div class="aw-focal__crop" style="aspect-ratio: 21/9"><img src="{{ $record->urlForWidth(800, ['webp', 'jpg']) }}" alt="" x-bind:style="`object-position: ${x * 100}% ${y * 100}%`"></div></div>
        <div><span class="aw-muted">Pătrat</span><div class="aw-focal__crop" style="aspect-ratio: 1"><img src="{{ $record->urlForWidth(800, ['webp', 'jpg']) }}" alt="" x-bind:style="`object-position: ${x * 100}% ${y * 100}%`"></div></div>
    </div>
    <p class="aw-muted">{{ $record->width }}×{{ $record->height }} px · {{ number_format(($record->size ?? 0) / 1024, 0, ',', '.') }} KB · {{ count((array) $record->variants) }} variante{{ \App\Modules\Cms\Services\MediaProcessor::supportsAvif() ? '' : ' (AVIF indisponibil pe acest server — se folosesc WebP și JPEG)' }}</p>
</div>
@endif
