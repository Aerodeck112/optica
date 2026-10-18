@props(['step' => null, 'steps' => []])
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $step ? __('install.steps.'.$step).' · ' : '' }}{{ __('install.title') }}</title>
    <link rel="icon" href="{{ asset('images/logo-optofarm.png') }}">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-dvh bg-paper">
<main class="mx-auto max-w-2xl px-4 py-8 sm:py-12">
    <div class="flex flex-wrap items-center gap-4">
        <img src="{{ asset('images/logo-optofarm.png') }}" alt="" class="h-12 w-12">
        <p class="flex-1 text-lg font-semibold text-heading">{{ __('install.title') }}</p>
        <nav class="flex rounded-lg bg-white p-0.5 text-sm font-semibold ring-1 ring-line" aria-label="{{ __('crm.language') }}">
            @foreach (['ro' => 'Română', 'hu' => 'Magyar'] as $code => $label)
                <a href="?lang={{ $code }}" lang="{{ $code }}" @class(['min-h-9 rounded-md px-3 py-2', 'bg-ink text-white' => app()->getLocale() === $code, 'text-ink' => app()->getLocale() !== $code])
                   @if (app()->getLocale() === $code) aria-current="true" @endif>{{ $label }}</a>
            @endforeach
        </nav>
    </div>
    @if ($step)
        @php($index = array_search($step, $steps, true))
        <p class="mt-8 text-sm text-muted">{{ __('install.step_of', ['current' => $index + 1, 'total' => count($steps)]) }}</p>
        <ol class="mt-2 flex gap-1" role="list" aria-hidden="true">
            @foreach ($steps as $i => $s)<li @class(['h-1.5 flex-1 rounded-full', 'bg-ink' => $i <= $index, 'bg-line' => $i > $index])></li>@endforeach
        </ol>
        <h1 class="mt-4 text-2xl font-semibold text-heading">{{ __('install.steps.'.$step) }}</h1>
    @endif
    @if (session('status'))<p class="mt-4 rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ session('status') }}</p>@endif
    @if ($errors->any())
        <div class="mt-4 rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad" role="alert">
            <ul class="list-disc space-y-1 pl-5">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif
    <div class="panel mt-6 p-5 sm:p-6">{{ $slot }}</div>
</main>
</body>
</html>
