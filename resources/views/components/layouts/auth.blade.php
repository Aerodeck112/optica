@props(['title'])
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · Optica Optofarm</title>
    <link rel="icon" href="{{ asset('images/logo-optofarm.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh bg-ink">
    <main class="grid min-h-dvh lg:grid-cols-[minmax(0,1fr)_minmax(0,34rem)]">
        <section class="relative hidden overflow-hidden lg:flex lg:flex-col lg:justify-end lg:p-14" aria-hidden="true">
            {{-- A single lens outline, the brand mark enlarged: the one decorative element of the staff area. --}}
            <svg class="pointer-events-none absolute -left-24 top-16 h-[38rem] w-[38rem] text-sky/25" viewBox="0 0 200 200" fill="none">
                <ellipse cx="100" cy="100" rx="92" ry="64" stroke="currentColor" stroke-width="1.5"/>
                <ellipse cx="100" cy="100" rx="70" ry="48" stroke="currentColor" stroke-width="1"/>
            </svg>
            <p class="relative max-w-md text-3xl font-light leading-snug text-white">{{ __('crm.brand.tagline') }}</p>
            <p class="relative mt-4 text-sm text-mist-300">{{ __('crm.brand.staff_area') }}</p>
        </section>
        <section class="flex flex-col justify-center bg-paper px-5 py-10 sm:px-12">
            <div class="mx-auto w-full max-w-sm">
                <img src="{{ asset('images/logo-optofarm.png') }}" alt="Optica Optofarm" class="mb-8 h-20 w-20">
                {{ $slot }}
            </div>
        </section>
    </main>
</body>
</html>
