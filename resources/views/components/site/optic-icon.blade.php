@props(['name' => 'eye'])
{{-- Line icons for eye examinations, drawn for this site (24 × 24, stroke = currentColor). --}}
@php
    // Service code → the instrument or structure the examination is about.
    $icon = [
        'prescriptie-ochelari-lentile-contact' => 'glasses',
        'screening-oftalmologic' => 'chart',
        'biomicroscop' => 'slit-lamp',
        'fund-de-ochi-oct' => 'fundus',
        'ecografie-oculara' => 'ultrasound',
        'gonioscopie' => 'gonio',
        'tonometrie' => 'tonometer',
        'oct-unghi-pahimetrie' => 'cornea-layers',
        'topografie-corneana' => 'topography',
        'camp-vizual' => 'field',
        'examen-ortoptic-sinoptofor' => 'binocular',
        'management-miopie-ambliopie' => 'occluder',
    ][$name] ?? $name;
@endphp
<svg {{ $attributes->merge(['class' => 'h-6 w-6']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($icon)
        @case('glasses')
            <circle cx="6.5" cy="13" r="3.5"/><circle cx="17.5" cy="13" r="3.5"/><path d="M10 12.5c1.3-1 2.7-1 4 0M3 13 2 8.5M21 13l1-4.5"/>
            @break
        @case('chart')
            {{-- One large and two small Landolt rings, as on an eye chart --}}
            <path d="M15 5.4A3.2 3.2 0 1 0 15 7.6"/><path d="M8.7 12.3a1.8 1.8 0 1 0 1 1.5"/><path d="M16.5 12.2a1.8 1.8 0 1 0-1.3 0"/><path d="M5 19h14"/>
            @break
        @case('slit-lamp')
            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.6"/><path d="M15.5 2v20" stroke-width="2.2"/>
            @break
        @case('fundus')
            <circle cx="12" cy="12" r="9"/><circle cx="9" cy="11" r="1.6"/><path d="M10.4 10.2c2-1.6 4.5-2.4 7.6-2.1M10.4 11.9c2.2 1.4 4.6 2.6 7.4 2.6M8.2 9.6C7 7.6 7.4 5.6 8.6 4.2M8.4 12.5c-1 2.2-.7 4.5.7 6.4"/>
            @break
        @case('ultrasound')
            <circle cx="16" cy="12" r="5"/><circle cx="16" cy="12" r="1.8"/><path d="M8 8.5a5 5 0 0 0 0 7M5 6.5a8.5 8.5 0 0 0 0 11M2.2 4.7a12 12 0 0 0 0 14.6"/>
            @break
        @case('gonio')
            <path d="M3 18a9 9 0 0 1 18 0"/><path d="M7 18a5 5 0 0 1 10 0"/><path d="M12 4l5 7M12 4 7 11"/><path d="M12 4v-1"/>
            @break
        @case('tonometer')
            <path d="M3.5 16a8.5 8.5 0 1 1 17 0"/><path d="M12 16l4.2-5.2"/><circle cx="12" cy="16" r="1.2"/><path d="M5.5 16h1M17.5 16h1M12 7.5v1M7.6 9.8l.7.7M16.4 9.8l-.7.7"/>
            @break
        @case('cornea-layers')
            <path d="M4 19c0-7.5 3.6-13 8-15"/><path d="M8 19c0-6.3 2.6-11 6.4-13"/><path d="M12 8h7M12.6 11H20M13.4 14H20"/><path d="M19 6.5v9"/>
            @break
        @case('topography')
            <ellipse cx="12" cy="12" rx="9.5" ry="8"/><ellipse cx="12" cy="12" rx="6.5" ry="5.2"/><ellipse cx="12" cy="12" rx="3.5" ry="2.6"/><circle cx="12" cy="12" r=".6" fill="currentColor"/>
            @break
        @case('field')
            <circle cx="12" cy="12" r="9"/><path d="M12 3v18M3 12h18"/><circle cx="7.5" cy="7.5" r=".7" fill="currentColor"/><circle cx="16.5" cy="8.5" r=".7" fill="currentColor"/><circle cx="8.5" cy="16" r=".7" fill="currentColor"/><circle cx="16" cy="15.5" r=".7" fill="currentColor"/>
            @break
        @case('binocular')
            <circle cx="6" cy="12" r="3"/><circle cx="18" cy="12" r="3"/><path d="M8.7 10.7 12 3l3.3 7.7"/><path d="M6 12h.01M18 12h.01"/>
            @break
        @case('occluder')
            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.6"/><path d="M4 4l16 16"/>
            @break
        @case('lens')
            <path d="M9 3c-2.5 5-2.5 13 0 18M15 3c2.5 5 2.5 13 0 18M9 3h6M9 21h6"/>
            @break
        @default
            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="1" fill="currentColor"/>
    @endswitch
</svg>
