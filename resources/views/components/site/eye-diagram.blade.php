{{-- Schematic eye: parallel light passes the cornea and the crystalline lens and is focused on the retina (the yellow point). --}}
<figure {{ $attributes->merge(['class' => 'text-ink']) }}>
    <svg viewBox="0 0 420 230" class="h-auto w-full" role="img" aria-labelledby="eye-diagram-title">
        <title id="eye-diagram-title">{{ __('site.eye.title') }}</title>
        {{-- Incoming parallel rays, bent by the cornea and the lens, meeting on the retina --}}
        <g fill="none" stroke="var(--color-sky)" stroke-width="1.6" stroke-linecap="round">
            <path d="M8 78 L176 78 L232 92 L342 115"/>
            <path d="M8 98 L176 98 L232 104 L342 115"/>
            <path d="M8 132 L176 132 L232 126 L342 115"/>
            <path d="M8 152 L176 152 L232 138 L342 115"/>
        </g>
        {{-- Globe and retina --}}
        <path d="M206 30 C290 26 350 66 352 115 C350 164 290 204 206 200 C180 199 162 194 150 186" fill="none" stroke="currentColor" stroke-width="2"/>
        <path d="M150 44 C162 36 180 31 206 30" fill="none" stroke="currentColor" stroke-width="2"/>
        <path d="M296 52 C330 72 344 94 345 115 C344 136 330 158 296 178" fill="none" stroke="var(--color-ink)" stroke-width="5" stroke-opacity=".22"/>
        {{-- Cornea --}}
        <path d="M168 58 C148 80 144 150 168 172" fill="none" stroke="currentColor" stroke-width="2.4"/>
        {{-- Iris --}}
        <path d="M186 52 L186 84 M186 146 L186 178" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        {{-- Crystalline lens --}}
        <path d="M222 74 C204 96 204 134 222 156 C240 134 240 96 222 74 Z" fill="var(--color-mist)" stroke="currentColor" stroke-width="2"/>
        {{-- Optic nerve --}}
        <path d="M348 96 C366 98 384 100 406 98 M348 134 C366 132 384 130 406 132" fill="none" stroke="currentColor" stroke-width="2"/>
        {{-- Focal point on the retina --}}
        <circle cx="342" cy="115" r="7" fill="var(--color-lens)" stroke="var(--color-ink)" stroke-width="1.5"/>
        <g font-family="Inter Variable, sans-serif" font-size="12.5" fill="currentColor">
            <text x="128" y="214">{{ __('site.eye.cornea') }}</text>
            <path d="M152 202 L158 176" stroke="currentColor" stroke-width="1"/>
            <text x="206" y="22">{{ __('site.eye.lens') }}</text>
            <path d="M226 28 L223 70" stroke="currentColor" stroke-width="1"/>
            <text x="306" y="222" text-anchor="middle">{{ __('site.eye.retina') }}</text>
            <path d="M318 208 L334 178" stroke="currentColor" stroke-width="1"/>
        </g>
    </svg>
    <figcaption class="mt-2 text-sm text-muted">{{ __('site.eye.caption') }}</figcaption>
</figure>
