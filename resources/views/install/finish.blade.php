<x-layouts.install :step="$step" :steps="$steps">
    <p class="rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ __('install.finish.heading') }}. {{ __('install.finish.locked') }}</p>
    <h2 class="mt-6 text-base font-semibold text-heading">Cron</h2>
    <p class="mt-1 text-sm">{{ __('install.finish.cron') }}</p>
    <pre class="mt-2 overflow-x-auto whitespace-pre-wrap break-all rounded-lg bg-ink p-3 text-xs text-white"><code>{{ $cron }}</code></pre>
    <p class="mt-2 text-xs text-muted">{{ __('install.finish.cron_hint') }}</p>
    <h2 class="mt-6 text-base font-semibold text-heading">{{ __('install.finish.recovery') }}</h2>
    <p class="mt-2 break-all rounded-lg bg-paper px-3 py-2 font-mono text-sm ring-1 ring-line">{{ $recoveryToken }}</p>
    <p class="mt-2 text-xs text-muted">{{ __('install.finish.recovery_hint') }}</p>
    <a href="{{ url('/crm/login') }}" class="btn-primary mt-6">{{ __('install.finish.login') }}</a>
</x-layouts.install>
