<x-layouts.install :step="$step" :steps="$steps">
    <p class="text-sm text-muted">{{ __('install.req.intro') }}</p>
    <ul class="mt-4 divide-y divide-line" role="list">
        @foreach ($checks as $c)
            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5 text-sm">
                <span class="{{ ['ok' => 'badge-ok', 'warning' => 'badge-warn', 'error' => 'badge-bad'][$c['level']] }}">{{ __('install.req.'.$c['level']) }}</span>
                <span class="flex-1">{{ $c['label'] }}</span>
                @if ($c['detail'] !== '')<span class="text-xs text-muted">{{ $c['detail'] }}</span>@endif
            </li>
        @endforeach
    </ul>
    @if (collect($checks)->contains('level', 'error'))
        <p class="mt-4 text-sm text-bad">{{ __('install.req.blocked') }}</p>
    @else
        <a href="{{ route('install.step', 'database') }}" class="btn-primary mt-6">{{ __('install.continue') }}</a>
    @endif
</x-layouts.install>
