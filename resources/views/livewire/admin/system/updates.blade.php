@php($tz = config('optica.clinic_timezone'))
@php($status = $journal['status'] ?? null)
<div class="max-w-5xl" @if ($status === 'running' && ! $stalled) wire:poll.750ms="tick" @endif>
    <p class="max-w-prose text-sm text-muted">{{ __('system.updates.intro') }}</p>

    <dl class="mt-6 grid gap-3 sm:grid-cols-3">
        <div class="panel px-4 py-3"><dt class="text-xs text-muted">{{ __('system.updates.current') }}</dt><dd class="text-xl font-semibold text-heading">{{ $version }}</dd></div>
        <div class="panel px-4 py-3"><dt class="text-xs text-muted">{{ __('system.updates.keys') }}</dt>
            <dd class="text-sm">@if ($keys)<span class="badge-ok">{{ trans_choice('system.updates.keys_count', $keys, ['count' => $keys]) }}</span>@else<span class="badge-warn">{{ __('system.updates.not_configured') }}</span>@endif</dd></div>
        <div class="panel px-4 py-3"><dt class="text-xs text-muted">{{ __('system.updates.recovery') }}</dt>
            <dd class="text-sm">@if ($hasRecovery)<span class="badge-ok">{{ __('system.updates.recovery_set') }}</span>@else<span class="badge-warn">{{ __('system.updates.recovery_missing') }}</span>@endif
                <button type="button" class="btn-link ml-1 text-xs" wire:click="newRecoveryToken" wire:confirm="{{ __('system.updates.recovery_confirm') }}">{{ __('system.updates.recovery_new') }}</button></dd></div>
    </dl>
    @if ($newToken)
        <div class="mt-4 rounded-lg bg-warn-bg px-4 py-3 text-sm" role="status">
            <p class="font-semibold">{{ __('system.updates.recovery_shown_once') }}</p>
            <p class="mt-1 break-all font-mono">{{ $newToken }}</p>
        </div>
    @endif
    @if (! $keys)<p class="mt-4 text-sm text-muted">{{ __('system.updates.keys_hint') }}</p>@endif

    <div aria-live="polite">
        @foreach ($packageErrors as $e)<p class="mt-4 rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad" role="alert">{{ $e }}</p>@endforeach
    </div>

    @if (in_array($status, ['verified', 'running', 'failed'], true))
        <section class="panel mt-6 p-5" aria-labelledby="up-current">
            <h2 id="up-current" class="text-base font-semibold text-heading">{{ __('system.updates.package_title', ['from' => $journal['from_version'], 'to' => $journal['to_version']]) }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('system.updates.plan', ['files' => count($journal['apply']), 'deleted' => count($journal['delete'])]) }}</p>
            @if (! empty($journal['changelog'][app()->getLocale()]))
                <details class="mt-3" @if ($status === 'verified') open @endif>
                    <summary class="cursor-pointer text-sm font-medium text-ink">{{ __('system.updates.changelog') }}</summary>
                    <div class="mt-2 max-h-64 overflow-y-auto whitespace-pre-line rounded-lg bg-paper p-3 text-sm ring-1 ring-line">{{ preg_replace('/^#+\s*/m', '', $journal['changelog'][app()->getLocale()]) }}</div>
                </details>
            @endif
            @if (! empty($journal['migration_notes'][app()->getLocale()]))
                <p class="mt-3 text-sm"><span class="font-medium">{{ __('system.updates.migration_notes') }}:</span> {{ $journal['migration_notes'][app()->getLocale()] }}</p>
            @endif

            @if ($status === 'verified')
                <p class="mt-4 text-sm">{{ __('system.updates.before_start') }}</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" class="btn-primary" wire:click="start" wire:confirm="{{ __('system.updates.confirm_start') }}">{{ __('system.updates.start') }}</button>
                    <button type="button" class="btn-secondary" wire:click="cancel">{{ __('system.updates.cancel') }}</button>
                </div>
            @else
                @php($stepIndex = array_search($journal['step'], \App\Domain\System\UpdateRunner::STEPS, true))
                <ol class="mt-4 grid gap-2 sm:grid-cols-7" role="list">
                    @foreach (\App\Domain\System\UpdateRunner::STEPS as $i => $s)
                        <li @class(['rounded-lg px-2 py-1.5 text-center text-xs', 'bg-ok-bg text-ok' => $i < $stepIndex, 'bg-ink text-white' => $i === $stepIndex, 'bg-paper text-muted ring-1 ring-line' => $i > $stepIndex])
                            @if ($i === $stepIndex) aria-current="step" @endif>{{ __('system.updates.steps.'.$s) }}</li>
                    @endforeach
                </ol>
                @if ($journal['step'] === 'apply')
                    <p class="mt-2 text-xs text-muted">{{ $journal['apply_index'] }} / {{ count($journal['apply']) }}</p>
                @endif
                @if ($stalled)
                    <div class="mt-4 rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad" role="alert">{{ $status === 'failed' ? __('system.updates.failed_hint') : __('system.updates.stalled_hint') }}</div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @if ($status === 'running')<button type="button" class="btn-secondary" wire:click="tick">{{ __('system.updates.resume') }}</button>@endif
                        <button type="button" class="btn-primary" wire:click="rollback" wire:confirm="{{ __('system.updates.confirm_rollback') }}">{{ __('system.updates.rollback') }}</button>
                    </div>
                @else
                    <p class="mt-4 text-sm" role="status">{{ __('system.updates.running') }}</p>
                @endif
            @endif
        </section>
    @else
        @if ($status === 'done')
            <p class="mt-6 rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ __('system.updates.done', ['version' => $journal['to_version']]) }}</p>
        @elseif ($status === 'rolled_back')
            <p class="mt-6 rounded-lg bg-warn-bg px-4 py-3 text-sm" role="status">{{ __('system.updates.rolled_back', ['version' => $journal['from_version']]) }}@if (! empty($journal['error'])) {{ __('system.updates.reason') }}: {{ \Illuminate\Support\Str::limit($journal['error'], 200) }}@endif</p>
        @endif
        <form wire:submit="checkPackage" class="panel mt-6 p-5">
            <h2 class="text-base font-semibold text-heading">{{ __('system.updates.upload_title') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('system.updates.upload_hint') }}</p>
            <div class="mt-3 flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1">
                    <label for="up-file" class="field-label">{{ __('system.updates.package') }}</label>
                    <input id="up-file" type="file" wire:model="package" accept=".zip,application/zip" class="input" @disabled(! $keys)>
                    @error('package')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="btn-primary" wire:loading.attr="disabled" @disabled(! $keys)>{{ __('system.updates.check') }}</button>
            </div>
            <p class="mt-2 text-xs text-muted" wire:loading wire:target="package,checkPackage">{{ __('system.updates.checking') }}</p>
        </form>
    @endif

    @if (! empty($journal['log']) && in_array($status, ['running', 'failed', 'done', 'rolled_back'], true))
        <details class="panel mt-6 p-4" @if ($status !== 'done') open @endif>
            <summary class="cursor-pointer text-sm font-semibold text-heading">{{ __('system.updates.log') }}</summary>
            <ol class="mt-2 space-y-1 text-xs" role="list">
                @foreach (array_slice($journal['log'], -30) as $entry)
                    <li @class(['text-bad' => ($entry['level'] ?? '') === 'error', 'text-muted' => ($entry['level'] ?? '') !== 'error'])>{{ \Carbon\CarbonImmutable::parse($entry['at'])->setTimezone($tz)->format('H:i:s') }} · {{ $entry['message'] }}</li>
                @endforeach
            </ol>
        </details>
    @endif

    <section class="panel mt-6 overflow-x-auto" aria-labelledby="up-history">
        <h2 id="up-history" class="border-b border-line px-4 py-3 text-base font-semibold text-heading">{{ __('system.updates.history') }}</h2>
        <table class="table-base table-stack">
            <thead><tr>
                <th scope="col">{{ __('system.updates.date') }}</th>
                <th scope="col">{{ __('system.updates.versions') }}</th>
                <th scope="col">{{ __('crm.fields.status') }}</th>
                <th scope="col">{{ __('system.updates.by') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($history as $h)
                <tr>
                    <td>{{ \Carbon\CarbonImmutable::parse($h->created_at)->setTimezone($tz)->format('d.m.Y H:i') }}</td>
                    <td data-label="{{ __('system.updates.versions') }}">{{ $h->from_version }} → {{ $h->to_version ?? '?' }}</td>
                    <td data-label="{{ __('crm.fields.status') }}"><span class="{{ ['done' => 'badge-ok', 'rolled_back' => 'badge-warn', 'rejected' => 'badge-bad', 'failed' => 'badge-bad'][$h->status] ?? 'badge-neutral' }}">{{ __('system.updates.statuses.'.$h->status) }}</span>
                        @if ($h->status === 'rejected' && $h->errors)<span class="mt-1 block text-xs text-muted">{{ \Illuminate\Support\Str::limit($h->errors, 160) }}</span>@endif</td>
                    <td data-label="{{ __('system.updates.by') }}">{{ $h->user_name ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-8 text-center text-sm text-muted">{{ __('crm.empty_list') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
</div>
