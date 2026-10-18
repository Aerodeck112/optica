@php
    use App\Support\Money;
    $pct = fn ($v) => $v === null ? '—' : number_format($v, 1, ',', '.').' %';
    $num = fn ($v) => $v === null ? '—' : number_format($v, is_float($v) ? 1 : 0, ',', '.');
    $delta = function ($now, $before) {
        if ($now === null || $before === null) {
            return null;
        }
        $diff = $now - $before;
        $rel = $before ? round($diff / $before * 100, 1) : null;

        return ['diff' => $diff, 'rel' => $rel];
    };
    $chips = collect([
        'locations' => collect($f->locationIds)->map(fn ($id) => $locations->firstWhere('id', $id)?->name())->filter(),
        'specialists' => collect($f->specialistIds)->map(fn ($id) => $specialists->firstWhere('id', $id)?->display_name)->filter(),
        'services' => collect($f->serviceIds)->map(fn ($id) => $services->firstWhere('id', $id)?->name())->filter(),
        'statuses' => collect($f->statuses)->map(fn ($s) => __('appointments.status.'.$s)),
        'sources' => collect($f->sources)->map(fn ($s) => __('appointments.source.'.$s)),
        'locales' => collect($f->locales)->map(fn ($l) => strtoupper($l)),
        'creators' => collect($f->creators)->map(fn ($c) => $c === 'patient' ? __('reports.creator_patient') : $creators->firstWhere('id', (int) $c)?->name)->filter(),
    ])->filter->isNotEmpty();
@endphp
<div class="max-w-7xl">
    {{-- Filters: one row, applied to every view (RAP-02). --}}
    <section class="panel p-4" aria-labelledby="filters-title">
        <h2 id="filters-title" class="sr-only">{{ __('reports.filters') }}</h2>
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label for="r-from" class="field-label">{{ __('finance.from') }}</label>
                <input id="r-from" type="date" wire:model.live="filters.from" class="input">
            </div>
            <div>
                <label for="r-to" class="field-label">{{ __('finance.to') }}</label>
                <input id="r-to" type="date" wire:model.live="filters.to" class="input">
            </div>
            <div>
                <label for="r-basis" class="field-label">{{ __('reports.basis') }}</label>
                <select id="r-basis" wire:model.live="filters.basis" class="input">
                    @foreach (\App\Domain\Reports\ReportFilters::BASES as $b)<option value="{{ $b }}">{{ __('reports.bases.'.$b) }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="r-compare" class="field-label">{{ __('reports.compare') }}</label>
                <select id="r-compare" wire:model.live="filters.compare" class="input">
                    @foreach (\App\Domain\Reports\ReportFilters::COMPARE as $c)<option value="{{ $c }}">{{ __('reports.compares.'.$c) }}</option>@endforeach
                </select>
            </div>
            <div class="flex flex-wrap gap-1" role="group" aria-label="{{ __('reports.presets_label') }}">
                @foreach (['today', '7d', 'month', 'last_month', 'year'] as $p)
                    <button type="button" class="btn-secondary min-h-10 px-3 text-xs" wire:click="preset('{{ $p }}')">{{ __('reports.presets.'.$p) }}</button>
                @endforeach
            </div>
            <button type="button" class="btn-link ml-auto text-sm" wire:click="$toggle('moreFilters')" aria-expanded="{{ $moreFilters ? 'true' : 'false' }}" aria-controls="more-filters">{{ __('reports.more_filters') }}</button>
        </div>

        @if ($moreFilters)
            <div id="more-filters" class="mt-4 grid gap-4 border-t border-line pt-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    'locations' => $locations->map(fn ($l) => [$l->id, $l->name()]),
                    'specialists' => $specialists->map(fn ($s) => [$s->id, $s->display_name]),
                    'services' => $services->map(fn ($s) => [$s->id, $s->name()]),
                    'statuses' => collect($statuses)->map(fn ($s) => [$s, __('appointments.status.'.$s)]),
                    'sources' => collect($sourcesList)->map(fn ($s) => [$s, __('appointments.source.'.$s)]),
                    'creators' => collect([['patient', __('reports.creator_patient')]])->merge($creators->map(fn ($u) => [(string) $u->id, $u->name])),
                    'locales' => collect(config('optica.locales'))->map(fn ($l) => [$l, __('crm.languages.'.$l)]),
                ] as $key => $options)
                    <div>
                        <label for="mf-{{ $key }}" class="field-label">{{ __('reports.filter_labels.'.$key) }}</label>
                        <select id="mf-{{ $key }}" multiple size="4" wire:model.live="filters.{{ $key }}" class="input">
                            @foreach ($options as [$value, $label])<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                @endforeach
                <div>
                    <label for="mf-pt" class="field-label">{{ __('reports.filter_labels.patient_type') }}</label>
                    <select id="mf-pt" wire:model.live="filters.patient_type" class="input">
                        @foreach (\App\Domain\Reports\ReportFilters::PATIENT_TYPES as $t)<option value="{{ $t }}">{{ __('reports.patient_types.'.$t) }}</option>@endforeach
                    </select>
                    <p class="field-hint">{{ __('reports.patient_type_hint') }}</p>
                </div>
            </div>
        @endif

        @if ($chips->isNotEmpty() || $f->patientType !== 'all')
            <ul class="mt-3 flex flex-wrap gap-2 text-xs" role="list" aria-label="{{ __('reports.active_filters') }}">
                @foreach ($chips as $key => $values)
                    <li><span class="badge-info">{{ __('reports.filter_labels.'.$key) }}: {{ $values->join(', ') }}
                        <button type="button" class="ml-1 font-bold" wire:click="clearFilter('{{ $key }}')" aria-label="{{ __('reports.remove_filter', ['name' => __('reports.filter_labels.'.$key)]) }}">×</button></span></li>
                @endforeach
                @if ($f->patientType !== 'all')
                    <li><span class="badge-info">{{ __('reports.patient_types.'.$f->patientType) }} <button type="button" class="ml-1 font-bold" wire:click="clearFilter('patient_type')" aria-label="{{ __('reports.remove_filter', ['name' => __('reports.filter_labels.patient_type')]) }}">×</button></span></li>
                @endif
                <li><button type="button" class="btn-link text-xs" wire:click="resetFilters">{{ __('reports.reset_filters') }}</button></li>
            </ul>
        @endif

        <div class="mt-3 flex flex-wrap items-end gap-3 border-t border-line pt-3">
            <div>
                <label for="saved" class="field-label">{{ __('reports.saved_filters') }}</label>
                <select id="saved" wire:model.live="savedId" class="input">
                    <option value="">—</option>
                    @foreach ($savedReports as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                </select>
            </div>
            @if ($savedId)<button type="button" class="btn-link text-xs text-bad" wire:click="deleteSaved">{{ __('cms.media.remove') }}</button>@endif
            <form wire:submit="saveFilters" class="flex items-end gap-2">
                <div>
                    <label for="save-name" class="field-label">{{ __('reports.saved_name') }}</label>
                    <input id="save-name" type="text" wire:model="saveName" class="input" maxlength="120">
                </div>
                <button type="submit" class="btn-secondary">{{ __('reports.save_filters') }}</button>
            </form>
            @error('saveName')<p class="field-error">{{ $message }}</p>@enderror
        </div>
    </section>

    <div aria-live="polite">
        @if ($notice)<div class="mt-4 rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ $notice }}</div>@endif
    </div>

    <nav class="-mx-4 mt-6 overflow-x-auto border-b border-line px-4 sm:mx-0 sm:px-0" aria-label="{{ __('reports.views') }}">
        <ul class="flex min-w-max gap-1" role="list">
            @foreach ($tabs as $t)
                <li><button type="button" wire:click="$set('tab', '{{ $t }}')" @class(['relative min-h-11 px-3 text-sm font-medium', 'text-ink after:absolute after:inset-x-2 after:bottom-0 after:h-0.5 after:rounded-full after:bg-ink' => $tab === $t, 'text-muted hover:text-ink' => $tab !== $t]) @if ($tab === $t) aria-current="page" @endif>{{ __('reports.tabs.'.$t) }}</button></li>
            @endforeach
        </ul>
    </nav>

    <div class="mt-4 flex flex-wrap items-center gap-3 text-sm">
        <p class="text-muted">{{ __('reports.period_line', ['from' => $f->from->format('d.m.Y'), 'to' => $f->to->format('d.m.Y'), 'basis' => __('reports.bases.'.$f->basis)]) }}</p>
        @can('reports.export')
            @if (in_array($tab, \App\Domain\Reports\ReportTables::REPORTS, true))
                <div class="ml-auto flex gap-2" role="group" aria-label="{{ __('reports.export') }}">
                    @foreach (\App\Models\ReportExport::FORMATS as $format)
                        <button type="button" class="btn-secondary min-h-10 px-3 text-xs uppercase" wire:click="export('{{ $format }}')">{{ $format }}</button>
                    @endforeach
                </div>
            @endif
        @endcan
    </div>

    <div class="mt-4" wire:loading.class="opacity-60">
        @switch($tab)
            @case('summary')
                @php($s = $summary)
                @php($p = $previous)
                <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" role="list">
                    @foreach ([
                        ['appointments', $num($s['appointments']), $delta($s['appointments'], $p['appointments'] ?? null), __('reports.rules.appointments')],
                        ['visits', $num($s['visits']), $delta($s['visits'], $p['visits'] ?? null), __('reports.rules.visits')],
                        ['unique_patients', $num($s['unique_patients']), $delta($s['unique_patients'], $p['unique_patients'] ?? null), __('reports.rules.unique_patients')],
                        ['reschedules', $num($s['reschedules']), $delta($s['reschedules'], $p['reschedules'] ?? null), __('reports.rules.reschedules')],
                        ['cancel_rate', $pct($s['cancel_rate']), $delta($s['cancel_rate'], $p['cancel_rate'] ?? null), __('reports.rules.cancel_rate', ['n' => $s['cancelled'], 'd' => $s['appointments']])],
                        ['no_show_rate', $pct($s['no_show_rate']), $delta($s['no_show_rate'], $p['no_show_rate'] ?? null), __('reports.rules.no_show_rate', ['n' => $s['no_show'], 'd' => $s['no_show_due']])],
                        ['avg_wait_days', $s['avg_wait_days'] === null ? '—' : __('reports.days', ['n' => $num($s['avg_wait_days'])]), $delta($s['avg_wait_days'], $p['avg_wait_days'] ?? null), __('reports.rules.avg_wait')],
                        ['avg_visit_minutes', $s['avg_visit_minutes'] === null ? '—' : __('crm.minutes', ['count' => $s['avg_visit_minutes']]), null, __('reports.rules.avg_visit', ['n' => $s['visits_with_duration']])],
                    ] as [$key, $value, $d, $rule])
                        <li class="panel p-4">
                            <p class="text-xs font-medium text-muted">{{ __('reports.kpi.'.$key) }}</p>
                            <p class="mt-1 text-2xl font-semibold tabular-nums text-heading">{{ $value }}</p>
                            @if ($d)
                                {{-- Change is shown neutrally: up is not automatically good. --}}
                                <p class="mt-1 text-xs text-body">
                                    <span aria-hidden="true">{{ $d['diff'] > 0 ? '▲' : ($d['diff'] < 0 ? '▼' : '■') }}</span>
                                    {{ __('reports.vs_previous', ['diff' => ($d['diff'] > 0 ? '+' : '').$num(is_float($d['diff']) ? round($d['diff'], 1) : $d['diff']), 'rel' => $d['rel'] === null ? '—' : ($d['rel'] > 0 ? '+' : '').number_format($d['rel'], 1, ',', '.').' %']) }}
                                </p>
                            @endif
                            <p class="mt-2 text-xs text-muted">{{ $rule }}</p>
                        </li>
                    @endforeach
                </ul>
                @if ($comparisonRange)<p class="mt-2 text-xs text-muted">{{ __('reports.compared_with', ['from' => $comparisonRange->from->format('d.m.Y'), 'to' => $comparisonRange->to->format('d.m.Y')]) }}</p>@endif
                <section class="panel mt-6 overflow-x-auto" aria-labelledby="by-status">
                    <h2 id="by-status" class="px-4 pt-4 text-base font-semibold">{{ __('reports.by_status') }}</h2>
                    @php($max = max(1, max($s['by_status'] ?: [0])))
                    <table class="table-base mt-2">
                        <thead><tr><th scope="col">{{ __('crm.fields.status') }}</th><th scope="col" class="text-right">{{ __('reports.value') }}</th><th scope="col" class="w-1/2"><span class="sr-only">{{ __('reports.bar') }}</span></th></tr></thead>
                        <tbody>
                        @foreach ($s['by_status'] as $status => $n)
                            <tr><td>{{ __('appointments.status.'.$status) }}</td><td class="text-right tabular-nums">{{ $n }}</td>
                                <td><div class="h-3 rounded-r bg-ink" style="width: {{ round($n / $max * 100, 1) }}%" title="{{ __('appointments.status.'.$status) }}: {{ $n }}" aria-hidden="true"></div></td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </section>
                @break

            @case('locations')
            @case('specialists')
            @case('services')
                @php($max = max(1, collect($rows)->max('total') ?? 1))
                <div class="panel overflow-x-auto">
                    <table class="table-base table-stack">
                        <thead><tr>
                            <th scope="col">{{ __('reports.name') }}</th>
                            <th scope="col" class="text-right">{{ __('reports.kpi.appointments') }}</th>
                            <th scope="col" class="hidden w-1/4 md:table-cell"><span class="sr-only">{{ __('reports.bar') }}</span></th>
                            <th scope="col" class="text-right">{{ __('reports.kpi.cancel_rate') }}</th>
                            <th scope="col" class="text-right">{{ __('reports.kpi.no_show_rate') }}</th>
                            <th scope="col" class="text-right">{{ __('reports.kpi.patients_in_row') }}</th>
                        </tr></thead>
                        <tbody>
                        @forelse ($rows as $r)
                            <tr wire:key="dim-{{ $r['key'] }}">
                                <td><button type="button" class="btn-link text-left" wire:click="drill('{{ $dimension }}', {{ (int) $r['key'] }})">{{ $names[$r['key']] ?? '—' }}</button></td>
                                <td class="text-right tabular-nums" data-label="{{ __('reports.kpi.appointments') }}">{{ $r['total'] }}</td>
                                <td class="hidden md:table-cell"><div class="h-3 rounded-r bg-ink" style="width: {{ round($r['total'] / $max * 100, 1) }}%" title="{{ $names[$r['key']] ?? '—' }}: {{ $r['total'] }}" aria-hidden="true"></div></td>
                                <td class="text-right tabular-nums" data-label="{{ __('reports.kpi.cancel_rate') }}">{{ $pct($r['cancel_rate']) }}</td>
                                <td class="text-right tabular-nums" data-label="{{ __('reports.kpi.no_show_rate') }}">{{ $pct($r['no_show_rate']) }}</td>
                                <td class="text-right tabular-nums" data-label="{{ __('reports.kpi.patients_in_row') }}">{{ $r['patients'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-10 text-center text-muted">{{ __('reports.no_data') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-muted">{{ __('reports.rules.rows_vs_total') }} {{ __('reports.drill_hint') }}</p>
                @break

            @case('matrix')
                @php($max = max(1, collect($cells)->flatten()->max() ?? 1))
                <div class="panel overflow-x-auto">
                    <table class="table-base text-sm">
                        <thead><tr><th scope="col">{{ __('crm.fields.location') }}</th>@foreach ($specialistNames as $sid => $sname)<th scope="col" class="text-right">{{ $sname }}</th>@endforeach<th scope="col" class="text-right">{{ __('reports.total') }}</th></tr></thead>
                        <tbody>
                        @forelse ($cells as $lid => $row)
                            <tr>
                                <th scope="row" class="text-left font-medium">{{ $locationNames[$lid] ?? '—' }}</th>
                                @foreach ($specialistNames as $sid => $sname)
                                    @php($n = $row[$sid] ?? 0)
                                    {{-- One hue, lighter to darker with the count; the number is always printed. --}}
                                    <td class="text-right tabular-nums" style="background: color-mix(in srgb, var(--color-ink) {{ $n ? round(8 + $n / $max * 40) : 0 }}%, transparent)" title="{{ $locationNames[$lid] ?? '' }} × {{ $sname }}: {{ $n }}">{{ $n ?: '·' }}</td>
                                @endforeach
                                <td class="text-right font-semibold tabular-nums">{{ array_sum($row) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="py-10 text-center text-muted">{{ __('reports.no_data') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @break

            @case('occupancy')
                <div class="panel overflow-x-auto">
                    <table class="table-base table-stack">
                        <thead><tr><th scope="col">{{ __('crm.fields.location') }}</th><th scope="col">{{ __('crm.fields.specialist') }}</th><th scope="col" class="text-right">{{ __('reports.available_minutes') }}</th><th scope="col" class="text-right">{{ __('reports.booked_minutes') }}</th><th scope="col" class="text-right">{{ __('reports.kpi.occupancy') }}</th><th scope="col" class="hidden w-1/5 md:table-cell"><span class="sr-only">{{ __('reports.bar') }}</span></th></tr></thead>
                        <tbody>
                        @forelse ($rows as $r)
                            <tr>
                                <td>{{ $locationNames[$r['location_id']] ?? '—' }}</td>
                                <td data-label="{{ __('crm.fields.specialist') }}">{{ $specialistNames[$r['specialist_id']] ?? '—' }}</td>
                                <td class="text-right tabular-nums" data-label="{{ __('reports.available_minutes') }}">{{ number_format($r['available'], 0, ',', '.') }}</td>
                                <td class="text-right tabular-nums" data-label="{{ __('reports.booked_minutes') }}">{{ number_format($r['booked'], 0, ',', '.') }}</td>
                                <td class="text-right font-semibold tabular-nums" data-label="{{ __('reports.kpi.occupancy') }}">{{ $pct($r['rate']) }}</td>
                                <td class="hidden md:table-cell"><div class="h-3 rounded-r bg-ink" style="width: {{ min(100, $r['rate'] ?? 0) }}%" aria-hidden="true"></div></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-10 text-center text-muted">{{ __('reports.no_data') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-muted">{{ __('reports.rules.occupancy') }}</p>
                @break

            @case('trend')
                @php($series = $trend['series'])
                @php($max = max(1, collect($series)->max('value')))
                @php($w = max(1, count($series)))
                <section class="panel p-4" aria-labelledby="trend-title">
                    <h2 id="trend-title" class="text-base font-semibold">{{ __('reports.trend_title', ['unit' => __('reports.units.'.$trend['unit'])]) }}</h2>
                    <svg viewBox="0 0 {{ $w * 10 }} 120" class="mt-4 h-48 w-full" preserveAspectRatio="none" role="img" aria-label="{{ __('reports.trend_title', ['unit' => __('reports.units.'.$trend['unit'])]) }}">
                        <line x1="0" y1="110" x2="{{ $w * 10 }}" y2="110" stroke="var(--color-line)" stroke-width="0.5" />
                        @foreach ($series as $i => $point)
                            @php($h = $point['value'] / $max * 100)
                            <rect x="{{ $i * 10 + 1 }}" y="{{ 110 - $h }}" width="8" height="{{ max($h, 0) }}" rx="1" fill="var(--color-ink)">
                                <title>{{ $trend['unit'] === 'month' ? $point['date']->format('m.Y') : $point['date']->format('d.m.Y') }}: {{ $point['value'] }}</title>
                            </rect>
                        @endforeach
                    </svg>
                    <details class="mt-3 text-sm">
                        <summary class="cursor-pointer text-ink">{{ __('reports.show_table') }}</summary>
                        <table class="table-base mt-2"><thead><tr><th scope="col">{{ __('reports.period') }}</th><th scope="col" class="text-right">{{ __('reports.kpi.appointments') }}</th></tr></thead>
                            <tbody>@foreach ($series as $point)<tr><td>{{ $trend['unit'] === 'month' ? $point['date']->format('m.Y') : $point['date']->format('d.m.Y') }}</td><td class="text-right tabular-nums">{{ $point['value'] }}</td></tr>@endforeach</tbody>
                        </table>
                    </details>
                </section>
                @break

            @case('distribution')
                @php($max = max(1, collect($grid)->flatten()->max() ?? 1))
                @php($hours = range(7, 20))
                <div class="panel overflow-x-auto p-4">
                    <table class="w-full min-w-[40rem] text-xs">
                        <caption class="mb-2 text-left text-base font-semibold text-heading">{{ __('reports.distribution_title') }}</caption>
                        <thead><tr><th scope="col"><span class="sr-only">{{ __('reports.day') }}</span></th>@foreach ($hours as $h)<th scope="col" class="px-0.5 font-medium text-muted">{{ $h }}</th>@endforeach</tr></thead>
                        <tbody>
                        @foreach (range(1, 7) as $d)
                            <tr>
                                <th scope="row" class="pr-2 text-left font-medium">{{ __('crm.weekdays.'.$d) }}</th>
                                @foreach ($hours as $h)
                                    @php($n = $grid[$d][$h] ?? 0)
                                    <td class="p-0.5"><div class="flex h-8 items-center justify-center rounded tabular-nums {{ $n / $max > 0.55 ? 'text-white' : 'text-heading' }}"
                                        style="background: color-mix(in srgb, var(--color-ink) {{ $n ? round(10 + $n / $max * 80) : 4 }}%, white)" title="{{ __('crm.weekdays.'.$d) }} {{ $h }}:00 – {{ $n }}">{{ $n ?: '' }}</div></td>
                                @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    <p class="mt-2 text-xs text-muted">{{ __('reports.rules.distribution') }}</p>
                </div>
                @break

            @case('financial')
                <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" role="list">
                    @foreach (['services_value', 'orders_value', 'received', 'advances', 'refunds', 'outstanding_now'] as $key)
                        <li class="panel p-4"><p class="text-xs font-medium text-muted">{{ __('reports.money.'.$key) }}</p>
                            <p class="mt-1 text-2xl font-semibold tabular-nums text-heading">{{ Money::format($money[$key]) }}</p>
                            <p class="mt-2 text-xs text-muted">{{ __('reports.money_rules.'.$key) }}</p></li>
                    @endforeach
                </ul>
                @if ($money['by_method'])
                    <div class="panel mt-4 overflow-x-auto">
                        <table class="table-base"><thead><tr><th scope="col">{{ __('finance.method') }}</th><th scope="col" class="text-right">{{ __('finance.net') }}</th></tr></thead>
                            <tbody>@foreach ($money['by_method'] as $m => $v)<tr><td>{{ __('finance.methods.'.$m) }}</td><td class="text-right tabular-nums">{{ Money::format($v) }}</td></tr>@endforeach</tbody></table>
                    </div>
                @endif
                <p class="mt-2 text-xs text-muted">{{ __('finance.not_fiscal') }}</p>
                @break

            @case('sources')
                <div class="grid gap-4 lg:grid-cols-2">
                    <section class="panel overflow-x-auto" aria-labelledby="src-a">
                        <h2 id="src-a" class="px-4 pt-4 text-base font-semibold">{{ __('reports.sources_appointments') }}</h2>
                        <table class="table-base mt-2"><thead><tr><th scope="col">{{ __('reports.source') }}</th><th scope="col" class="text-right">{{ __('reports.kpi.appointments') }}</th></tr></thead>
                            <tbody>@forelse ($sources['appointments'] as $r)<tr><td><button type="button" class="btn-link" wire:click="drill('source', '{{ $r['key'] }}')">{{ __('appointments.source.'.$r['key']) }}</button></td><td class="text-right tabular-nums">{{ $r['total'] }}</td></tr>@empty<tr><td colspan="2" class="py-6 text-center text-muted">{{ __('reports.no_data') }}</td></tr>@endforelse</tbody></table>
                    </section>
                    <section class="panel overflow-x-auto" aria-labelledby="src-l">
                        <h2 id="src-l" class="px-4 pt-4 text-base font-semibold">{{ __('reports.sources_leads') }}</h2>
                        <table class="table-base mt-2"><thead><tr><th scope="col">{{ __('crm.fields.type') }}</th><th scope="col" class="text-right">{{ __('reports.leads_total') }}</th><th scope="col" class="text-right">{{ __('reports.leads_converted') }}</th></tr></thead>
                            <tbody>@forelse ($sources['leads_by_type'] as $r)<tr><td>{{ __('leads.types.'.$r['type']) }}</td><td class="text-right tabular-nums">{{ $r['total'] }}</td><td class="text-right tabular-nums">{{ $r['converted'] }}</td></tr>@empty<tr><td colspan="3" class="py-6 text-center text-muted">{{ __('reports.no_data') }}</td></tr>@endforelse</tbody></table>
                        <p class="px-4 pb-4 text-xs text-muted">{{ __('reports.leads_without_utm_line', ['n' => $sources['leads_without_utm'], 'total' => $sources['leads_total']]) }}</p>
                    </section>
                </div>
                <p class="mt-2 text-xs text-muted">{{ __('reports.rules.sources') }}</p>
                @break

            @case('records')
                <div class="panel overflow-x-auto">
                    <table class="table-base table-stack">
                        <thead><tr><th scope="col">{{ __('crm.fields.time') }}</th><th scope="col">{{ __('crm.fields.patient') }}</th><th scope="col">{{ __('crm.fields.service') }}</th><th scope="col">{{ __('crm.fields.specialist') }}</th><th scope="col">{{ __('crm.fields.location') }}</th><th scope="col">{{ __('crm.fields.status') }}</th><th scope="col">{{ __('reports.source') }}</th></tr></thead>
                        <tbody>
                        @forelse ($records as $a)
                            <tr wire:key="rec-{{ $a->id }}">
                                <td class="whitespace-nowrap">{{ $a->starts_at->timezone($tz)->format('d.m.Y H:i') }}</td>
                                <td data-label="{{ __('crm.fields.patient') }}">@if ($withNames && $a->patient)<a href="{{ route('crm.patients.show', $a->patient) }}" wire:navigate class="btn-link">{{ $a->patient->fullName() }}</a>@elseif ($withNames){{ $a->contact_name ?? '—' }}@else{{ $a->patient?->code ?? '—' }}@endif</td>
                                <td data-label="{{ __('crm.fields.service') }}">{{ $a->service?->name() }}</td>
                                <td data-label="{{ __('crm.fields.specialist') }}">{{ $a->specialist?->display_name }}</td>
                                <td data-label="{{ __('crm.fields.location') }}">{{ $a->location?->name() }}</td>
                                <td data-label="{{ __('crm.fields.status') }}"><x-appointment-status :status="$a->status" /></td>
                                <td data-label="{{ __('reports.source') }}">{{ __('appointments.source.'.$a->source->value) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-10 text-center text-muted">{{ __('reports.no_data') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $records->links() }}</div>
                @break
        @endswitch
    </div>

    @can('reports.export')
        <div class="mt-8 grid gap-6 lg:grid-cols-2">
            <section class="panel p-4" aria-labelledby="exports-title" wire:poll.10s>
                <h2 id="exports-title" class="text-base font-semibold">{{ __('reports.my_exports') }}</h2>
                <ul class="mt-2 divide-y divide-line text-sm" role="list">
                    @forelse ($exports as $e)
                        <li class="flex flex-wrap items-center gap-3 py-2">
                            <span class="flex-1">{{ __('reports.tabs.'.$e->report) }} · {{ strtoupper($e->format) }} · {{ $e->created_at->timezone($tz)->format('d.m.Y H:i') }}</span>
                            @if ($e->status === 'done')
                                <a href="{{ route('crm.reports.download', $e->uuid) }}" class="btn-link">{{ __('patients.documents.download') }}</a>
                            @else
                                <span class="{{ $e->status === 'failed' ? 'badge-bad' : 'badge-info' }}">{{ __('reports.export_status.'.$e->status) }}</span>
                            @endif
                        </li>
                    @empty
                        <li class="py-2 text-muted">{{ __('reports.no_exports') }}</li>
                    @endforelse
                </ul>
                <p class="mt-2 text-xs text-muted">{{ __('reports.exports_hint') }}</p>
            </section>
            <section class="panel p-4" aria-labelledby="subs-title">
                <div class="flex items-center gap-3">
                    <h2 id="subs-title" class="flex-1 text-base font-semibold">{{ __('reports.subscriptions.title') }}</h2>
                    <button type="button" class="btn-link text-sm" wire:click="$toggle('showSubscriptions')" aria-expanded="{{ $showSubscriptions ? 'true' : 'false' }}">{{ $showSubscriptions ? __('reports.hide') : __('reports.show') }}</button>
                </div>
                @if ($showSubscriptions)
                    <ul class="mt-2 divide-y divide-line text-sm" role="list">
                        @foreach ($subscriptions as $sub)
                            <li class="py-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="flex-1 font-medium">{{ $sub->name }}</span>
                                    <button type="button" class="btn-link text-xs" wire:click="toggleSubscription({{ $sub->id }})">{{ $sub->is_active ? __('reports.subscriptions.pause') : __('reports.subscriptions.resume') }}</button>
                                    <button type="button" class="btn-link text-xs text-bad" wire:click="deleteSubscription({{ $sub->id }})" wire:confirm="{{ __('reports.subscriptions.confirm_delete') }}">{{ __('cms.media.remove') }}</button>
                                </div>
                                <p class="text-xs text-muted">{{ __('reports.tabs.'.$sub->report) }} · {{ __('reports.subscriptions.frequencies.'.$sub->frequency) }} · {{ strtoupper($sub->format) }} · {{ implode(', ', $sub->recipients) }}</p>
                                @if ($sub->last_sent_at)<p class="text-xs text-muted">{{ $sub->last_sent_at->timezone($tz)->format('d.m.Y H:i') }} – {{ $sub->last_result }}</p>@endif
                            </li>
                        @endforeach
                    </ul>
                    <form wire:submit="subscribe" class="mt-3 space-y-3 border-t border-line pt-3">
                        <x-admin.text model="subscription.name" :label="__('reports.subscriptions.name')" required />
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div><label for="sub-r" class="field-label">{{ __('reports.subscriptions.report') }}</label>
                                <select id="sub-r" wire:model="subscription.report" class="input">@foreach (\App\Domain\Reports\ReportTables::REPORTS as $r)@if ($r !== 'financial' || auth()->user()->can('reports.financial'))<option value="{{ $r }}">{{ __('reports.tabs.'.$r) }}</option>@endif @endforeach</select></div>
                            <div><label for="sub-f" class="field-label">{{ __('reports.subscriptions.frequency') }}</label>
                                <select id="sub-f" wire:model="subscription.frequency" class="input">@foreach (\App\Models\ReportSubscription::FREQUENCIES as $fr)<option value="{{ $fr }}">{{ __('reports.subscriptions.frequencies.'.$fr) }}</option>@endforeach</select></div>
                            <div><label for="sub-fmt" class="field-label">{{ __('reports.subscriptions.format') }}</label>
                                <select id="sub-fmt" wire:model="subscription.format" class="input">@foreach (\App\Models\ReportExport::FORMATS as $fmt)<option value="{{ $fmt }}">{{ strtoupper($fmt) }}</option>@endforeach</select></div>
                            <div><label for="sub-l" class="field-label">{{ __('reports.subscriptions.locale') }}</label>
                                <select id="sub-l" wire:model="subscription.locale" class="input">@foreach (config('optica.locales') as $l)<option value="{{ $l }}">{{ __('crm.languages.'.$l) }}</option>@endforeach</select></div>
                        </div>
                        <x-admin.text model="subscription.recipients" :label="__('reports.subscriptions.recipients')" :hint="__('reports.subscriptions.recipients_hint')" required />
                        <p class="text-xs text-muted">{{ __('reports.subscriptions.hint') }}</p>
                        <button type="submit" class="btn-primary">{{ __('reports.subscriptions.create') }}</button>
                    </form>
                @endif
            </section>
        </div>
    @endcan
</div>
