@php($tz = config('optica.clinic_timezone'))
@php($msg = fn ($code) => \App\Http\Controllers\Admin\ImportReportController::message($code))
<div class="max-w-6xl" @if ($run && $run->status === 'running') wire:poll.500ms="batch" @endif>
    <p class="max-w-prose text-sm text-muted">{{ __('system.import.intro') }}</p>
    <div aria-live="polite">
        @if ($notice)<div class="mt-4 rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ $notice }}</div>@endif
    </div>

    @if (! $run)
        <form wire:submit="upload" class="panel mt-6 grid gap-4 p-5 sm:grid-cols-3">
            <h2 class="text-base font-semibold text-heading sm:col-span-3">{{ __('system.import.new') }}</h2>
            <div>
                <label for="im-target" class="field-label">{{ __('system.import.target') }}</label>
                <select id="im-target" wire:model="target" class="input">
                    @foreach (array_keys(\App\Domain\Import\ImportRunner::TARGETS) as $t)<option value="{{ $t }}">{{ __('system.import.targets.'.$t) }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="im-source" class="field-label">{{ __('system.import.source') }}</label>
                <input id="im-source" wire:model="source" class="input" aria-describedby="im-source-hint">
                <p id="im-source-hint" class="mt-1 text-xs text-muted">{{ __('system.import.source_hint') }}</p>
                @error('source')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="im-file" class="field-label">{{ __('system.import.file') }}</label>
                <input id="im-file" type="file" wire:model="file" accept=".csv,.xlsx,text/csv" class="input">
                @error('file')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <p class="text-xs text-muted sm:col-span-2">{{ __('system.import.order_hint') }}</p>
            <div class="sm:text-right"><button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ __('system.import.upload') }}</button></div>
        </form>

        <section class="panel mt-6 overflow-x-auto" aria-labelledby="im-runs">
            <h2 id="im-runs" class="border-b border-line px-4 py-3 text-base font-semibold text-heading">{{ __('system.import.history') }}</h2>
            <table class="table-base table-stack">
                <thead><tr>
                    <th scope="col">{{ __('system.import.file') }}</th>
                    <th scope="col">{{ __('system.import.target') }}</th>
                    <th scope="col">{{ __('crm.fields.status') }}</th>
                    <th scope="col"><span class="sr-only">{{ __('crm.actions.actions') }}</span></th>
                </tr></thead>
                <tbody>
                @forelse ($runs as $r)
                    <tr wire:key="run-{{ $r->id }}">
                        <td><span class="font-medium text-heading">{{ $r->original_name }}</span>
                            <span class="block text-xs text-muted">{{ $r->created_at->timezone($tz)->format('d.m.Y H:i') }} · {{ $r->creator?->name ?? '—' }} · {{ $r->total_rows }} {{ __('system.import.rows') }}</span></td>
                        <td data-label="{{ __('system.import.target') }}">{{ __('system.import.targets.'.$r->target) }} <span class="text-xs text-muted">({{ $r->source_label }})</span></td>
                        <td data-label="{{ __('crm.fields.status') }}"><span class="{{ ['done' => 'badge-ok', 'failed' => 'badge-bad', 'running' => 'badge-info'][$r->status] ?? 'badge-neutral' }}">{{ __('system.import.statuses.'.$r->status) }}</span></td>
                        <td class="cell-actions text-right"><button type="button" class="btn-link text-sm" wire:click="$set('runUuid', '{{ $r->uuid }}')">{{ __('system.import.open') }}</button></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-8 text-center text-sm text-muted">{{ __('crm.empty_list') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
    @else
        <div class="mt-6 flex flex-wrap items-center gap-3">
            <h2 class="flex-1 text-lg font-semibold text-heading">{{ $run->original_name }} <span class="text-sm font-normal text-muted">· {{ __('system.import.targets.'.$run->target) }} · {{ $run->total_rows }} {{ __('system.import.rows') }}</span></h2>
            <span class="{{ ['done' => 'badge-ok', 'failed' => 'badge-bad', 'running' => 'badge-info'][$run->status] ?? 'badge-neutral' }}">{{ __('system.import.statuses.'.$run->status) }}</span>
            <button type="button" class="btn-secondary" wire:click="close">{{ __('system.import.back') }}</button>
        </div>

        @if ($run->status === 'uploaded')
            <section class="panel mt-4 p-5" aria-labelledby="im-map">
                <h3 id="im-map" class="text-base font-semibold text-heading">1. {{ __('system.import.mapping') }}</h3>
                <p class="mt-1 text-sm text-muted">{{ __('system.import.mapping_hint') }}</p>
                <form wire:submit="saveMapping" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($run->headers as $i => $header)
                        <div>
                            <label for="map-{{ $i }}" class="field-label truncate">{{ $header ?: '#'.($i + 1) }}</label>
                            <select id="map-{{ $i }}" wire:model="mapping.{{ $i }}" class="input">
                                <option value="">{{ __('system.import.ignore') }}</option>
                                @foreach ($fields as $field => $meta)<option value="{{ $field }}">{{ __('system.import.fields.'.$field) }}{{ ! empty($meta['required']) ? ' *' : '' }}</option>@endforeach
                            </select>
                        </div>
                    @endforeach
                    <div class="sm:col-span-2 lg:col-span-3"><button type="submit" class="btn-secondary">{{ __('system.import.save_mapping') }}</button></div>
                </form>
                @if ($missing)
                    <p class="mt-3 rounded-lg bg-warn-bg px-3 py-2 text-sm" role="alert">{{ __('system.import.missing', ['fields' => implode(', ', array_map(fn ($f) => __('system.import.fields.'.$f), $missing))]) }}</p>
                @endif
            </section>

            @if ($preview)
                <section class="panel mt-6 overflow-x-auto" aria-labelledby="im-preview">
                    <h3 id="im-preview" class="border-b border-line px-4 py-3 text-base font-semibold text-heading">2. {{ __('system.import.preview') }}</h3>
                    <table class="table-base table-stack text-sm">
                        <thead><tr>
                            <th scope="col">{{ __('system.import.row') }}</th>
                            <th scope="col">{{ __('system.import.values') }}</th>
                            <th scope="col">{{ __('system.import.outcome') }}</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($preview as $p)
                            <tr>
                                <td>{{ $p['row'] }}</td>
                                <td data-label="{{ __('system.import.values') }}" class="text-xs">@foreach ($p['values'] as $f => $v)@if ($v !== '' && $f !== 'cnp')<span class="mr-2"><span class="text-muted">{{ __('system.import.fields.'.$f) }}:</span> {{ \Illuminate\Support\Str::limit($v, 40) }}</span>@endif @endforeach</td>
                                <td data-label="{{ __('system.import.outcome') }}"><span class="{{ ['created' => 'badge-ok', 'updated' => 'badge-info', 'linked' => 'badge-info', 'duplicate' => 'badge-warn', 'error' => 'badge-bad'][$p['outcome']] ?? 'badge-neutral' }}">{{ __('system.import.outcomes.'.$p['outcome']) }}</span>
                                    @foreach ($p['messages'] as $m)<span class="mt-1 block text-xs text-muted">{{ $msg($m) }}</span>@endforeach</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </section>

                <section class="panel mt-6 p-5" aria-labelledby="im-sim">
                    <h3 id="im-sim" class="text-base font-semibold text-heading">3. {{ __('system.import.simulation') }}</h3>
                    <p class="mt-1 text-sm text-muted">{{ __('system.import.simulation_hint') }}</p>
                    <button type="button" class="btn-secondary mt-3" wire:click="simulate" wire:loading.attr="disabled" wire:target="simulate">{{ __('system.import.simulate') }}</button>
                    @if ($run->simulation)
                        <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-6">
                            @foreach ($run->simulation['stats'] as $outcome => $count)
                                <div class="rounded-lg bg-paper px-3 py-2 ring-1 ring-line"><dt class="text-xs text-muted">{{ __('system.import.outcomes.'.$outcome) }}</dt><dd class="text-lg font-semibold tabular-nums">{{ $count }}</dd></div>
                            @endforeach
                        </dl>
                        <a href="{{ route('admin.system.import.report', $run->uuid) }}" class="btn-link mt-3 inline-block text-sm">{{ __('system.import.download_report') }}</a>
                        @if ($run->target === 'appointments')
                            <label class="mt-4 flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" wire:model="notifyFuture" class="h-5 w-5 rounded border-line"> {{ __('system.import.notify_future') }}</label>
                        @endif
                        <div class="mt-4">
                            <button type="button" class="btn-primary" wire:click="start" wire:confirm="{{ __('system.import.confirm_start') }}">4. {{ __('system.import.start') }}</button>
                        </div>
                    @endif
                </section>
            @endif
        @else
            <section class="panel mt-4 p-5" aria-labelledby="im-progress">
                <h3 id="im-progress" class="text-base font-semibold text-heading">{{ __('system.import.progress') }}</h3>
                @php($pct = $run->total_rows ? min(100, round($run->cursor / $run->total_rows * 100)) : 100)
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-line" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full bg-ink" style="width: {{ $pct }}%"></div></div>
                <p class="mt-2 text-sm">{{ $run->cursor }} / {{ $run->total_rows }}</p>
                @if ($run->status === 'failed')
                    <p class="mt-3 rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad" role="alert">{{ __('system.import.failed') }}</p>
                    <button type="button" class="btn-primary mt-3" wire:click="resume">{{ __('system.import.resume') }}</button>
                @endif
                @if ($reconciliation)
                    <h4 class="mt-5 text-sm font-semibold text-heading">{{ __('system.import.reconciliation') }}</h4>
                    <dl class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-6">
                        @foreach ($reconciliation['stats'] as $outcome => $count)
                            <div class="rounded-lg bg-paper px-3 py-2 ring-1 ring-line"><dt class="text-xs text-muted">{{ __('system.import.outcomes.'.$outcome) }}</dt><dd class="text-lg font-semibold tabular-nums">{{ $count }}</dd></div>
                        @endforeach
                    </dl>
                    <p @class(['mt-3 text-sm', 'text-ok' => $reconciliation['accounted'] === $reconciliation['rows'] || $run->status !== 'done', 'text-bad' => $reconciliation['accounted'] !== $reconciliation['rows'] && $run->status === 'done'])>
                        {{ __('system.import.reconciled', ['accounted' => $reconciliation['accounted'], 'rows' => $reconciliation['rows'], 'database' => $reconciliation['in_database'], 'source' => $run->source_label]) }}</p>
                    <a href="{{ route('admin.system.import.report', $run->uuid) }}" class="btn-link mt-2 inline-block text-sm">{{ __('system.import.download_report') }}</a>
                @endif
            </section>
        @endif

        @if (($run->issues ?? []) && $run->status !== 'uploaded' || ($run->status === 'uploaded' && $run->simulation && $run->issues))
            <section class="panel mt-6 overflow-x-auto" aria-labelledby="im-issues">
                <h3 id="im-issues" class="border-b border-line px-4 py-3 text-base font-semibold text-heading">{{ __('system.import.issues', ['count' => count($run->issues)]) }}</h3>
                <ul class="divide-y divide-line text-sm" role="list">
                    @foreach (array_slice($run->issues, 0, 100) as $issue)
                        <li class="flex flex-wrap gap-x-3 px-4 py-2"><span class="w-16 text-muted">#{{ $issue['row'] }}</span>
                            <span class="{{ ['duplicate' => 'badge-warn', 'error' => 'badge-bad'][$issue['outcome']] ?? 'badge-neutral' }}">{{ __('system.import.outcomes.'.$issue['outcome']) }}</span>
                            <span class="text-muted">{{ implode(' · ', array_map($msg, $issue['messages'])) }}</span></li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endif
</div>
