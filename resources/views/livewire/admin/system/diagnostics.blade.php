<div class="max-w-5xl" wire:poll.60s>
    <p class="max-w-prose text-sm text-muted">{{ __('system.diagnostics.intro') }}</p>
    @php($problems = $checks->flatten(1)->whereIn('status', ['error', 'warning'])->count())
    <p @class(['mt-4 rounded-lg px-4 py-3 text-sm', 'bg-ok-bg text-ok' => ! $problems, 'bg-warn-bg text-warn' => $problems]) role="status">
        {{ $problems ? trans_choice('system.diagnostics.problems', $problems, ['count' => $problems]) : __('system.diagnostics.all_ok') }}
    </p>
    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        @foreach ($checks as $group => $items)
            <section class="panel" aria-labelledby="dg-{{ $group }}">
                <h2 id="dg-{{ $group }}" class="border-b border-line px-4 py-3 text-base font-semibold text-heading">{{ __('system.diagnostics.groups.'.$group) }}</h2>
                <ul class="divide-y divide-line" role="list">
                    @foreach ($items as $c)
                        <li class="flex flex-wrap items-start gap-x-3 gap-y-1 px-4 py-3 text-sm">
                            <span class="{{ ['ok' => 'badge-ok', 'warning' => 'badge-warn', 'error' => 'badge-bad', 'info' => 'badge-neutral'][$c['status']] }} shrink-0">{{ __('system.diagnostics.status.'.$c['status']) }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-heading">{{ __('system.diagnostics.checks.'.$c['key'].'.label') }}</p>
                                @if ($c['status'] !== 'ok' && $c['status'] !== 'info' && \Illuminate\Support\Facades\Lang::has('system.diagnostics.checks.'.$c['key'].'.fix'))
                                    <p class="mt-0.5 text-xs text-muted">{{ __('system.diagnostics.checks.'.$c['key'].'.fix') }}</p>
                                @endif
                            </div>
                            @if ($c['detail'] !== '')<span class="max-w-full break-words text-xs text-muted">{{ $c['detail'] }}</span>@endif
                            @isset($c['link'])<a href="{{ $c['link'] }}" wire:navigate class="btn-link text-xs">{{ __('system.diagnostics.open') }}</a>@endisset
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</div>
