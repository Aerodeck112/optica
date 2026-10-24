@php
    $slot = 15;
    $rowsPerHour = 60 / $slot;
    $rowCount = ($maxHour - $minHour) * $rowsPerHour;
    $rowOf = fn ($time) => max(0, intdiv(((int) $time->format('G') - $minHour) * 60 + (int) $time->format('i'), $slot));
    $monthNames = ['', 'ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
    $dayNames = ['', 'Lu', 'Ma', 'Mi', 'Jo', 'Vi', 'Sâ', 'Du'];
    $fmtDay = fn ($d) => $dayNames[\Carbon\CarbonImmutable::parse($d)->isoWeekday()].' '.\Carbon\CarbonImmutable::parse($d)->format('j').' '.$monthNames[\Carbon\CarbonImmutable::parse($d)->month];
    $heading = match ($mode) {
        'week' => $fmtDay($days[0]).' – '.$fmtDay(end($days)).' '.$current->year,
        'month' => ucfirst($monthNames[$current->month]).' '.$current->year,
        default => $fmtDay($current->toDateString()).' '.$current->year,
    };
    $legend = ['confirmed' => 'Confirmată', 'pending' => 'În așteptare', 'checked_in' => 'Check-in', 'completed' => 'Finalizată', 'block' => 'Blocare'];
@endphp
<x-filament-panels::page>
    <div class="aw-cal aw-stack">
        <div class="aw-toolbar aw-between">
            <div class="aw-row">
                <x-filament::button color="gray" size="sm" wire:click="shift(-1)" icon="heroicon-o-chevron-left" aria-label="Anterior" />
                <x-filament::button color="gray" size="sm" wire:click="today">Azi</x-filament::button>
                <x-filament::button color="gray" size="sm" wire:click="shift(1)" icon="heroicon-o-chevron-right" aria-label="Următor" />
                <h2 class="aw-h" style="margin:0 0 0 .5rem; font-size:1.125rem" aria-live="polite">{{ $heading }}</h2>
                <input type="date" class="aw-select" wire:model.live="date" aria-label="Alege data">
            </div>
            <div class="aw-seg" role="group" aria-label="Vizualizare">
                <button type="button" wire:click="setMode('day')" aria-pressed="{{ $mode === 'day' ? 'true' : 'false' }}">Zi</button>
                <button type="button" wire:click="setMode('week')" aria-pressed="{{ $mode === 'week' ? 'true' : 'false' }}">Săptămână</button>
                <button type="button" wire:click="setMode('month')" aria-pressed="{{ $mode === 'month' ? 'true' : 'false' }}">Lună</button>
            </div>
        </div>

        <div class="aw-row aw-muted" style="font-size:.8125rem">
            @foreach ($legend as $key => $label)
                <span class="aw-ev aw-ev--{{ $key }}" style="display:inline-block;margin:0;padding:.125rem .5rem">{{ $label }}</span>
            @endforeach
            <span>Zona hașurată = închis. Rezervările includ pauzele dintre grupuri.</span>
        </div>

        @if ($resources->isEmpty())
            <div class="aw-card">Nu există resurse active. Adaugă arena în <strong>Configurare → Resurse</strong>.</div>
        @elseif ($mode === 'day')
            @php
                $dayDate = $current->toDateString();
                $window = $windows[$dayDate] ?? null;
                $dayEvents = array_filter($events, fn ($e) => $e['occ_start']->toDateString() === $dayDate || $e['start']->toDateString() === $dayDate);
            @endphp
            @if (! $window)
                <div class="aw-card"><strong>Închis</strong> în această zi (program sau excepție).</div>
            @elseif ($window['note'])
                <div class="aw-card">{{ $window['note'] }}</div>
            @endif
            <div style="overflow-x:auto">
                <div class="aw-cal-grid" style="grid-template-columns: 4rem repeat({{ $resources->count() }}, minmax(12rem, 1fr)); grid-template-rows: auto repeat({{ $rowCount }}, 1rem); min-width: {{ 4 + 12 * $resources->count() }}rem">
                    <div class="aw-cal-head" style="grid-row:1;grid-column:1"></div>
                    @foreach ($resources as $i => $resource)
                        <div class="aw-cal-head" style="grid-row:1;grid-column:{{ $i + 2 }}">{{ $resource->name }}@if ($resource->capacity) <span class="aw-muted">· max. {{ $resource->capacity }}</span>@endif</div>
                    @endforeach
                    @for ($h = $minHour; $h < $maxHour; $h++)
                        @php $r = ($h - $minHour) * $rowsPerHour + 2; @endphp
                        <div class="aw-cal-cell aw-cal-time" style="grid-row:{{ $r }} / span {{ $rowsPerHour }};grid-column:1">{{ sprintf('%02d:00', $h) }}</div>
                        @foreach ($resources as $i => $resource)
                            @php
                                $hourStart = $current->setTime($h, 0);
                                $closed = ! $window || $hourStart->lt($window['open']->setTimezone($hourStart->getTimezone())->startOfHour()) || $hourStart->gte($window['close']->setTimezone($hourStart->getTimezone()));
                            @endphp
                            <div class="aw-cal-cell {{ $closed ? 'aw-cal-closed' : '' }}" style="grid-row:{{ $r }} / span {{ $rowsPerHour }};grid-column:{{ $i + 2 }};min-height:0"></div>
                        @endforeach
                    @endfor
                    @foreach ($dayEvents as $e)
                        @php
                            $col = $resources->search(fn ($r) => $r->id === $e['resource_id']);
                            $startRow = $rowOf($e['occ_start']) + 2;
                            $span = max(2, (int) ceil($e['occ_start']->diffInMinutes($e['occ_end']) / $slot));
                        @endphp
                        @continue($col === false)
                        @if ($e['url'])
                            <a href="{{ $e['url'] }}" class="aw-ev aw-ev--{{ $e['status'] }}" style="grid-row:{{ $startRow }} / span {{ $span }};grid-column:{{ $col + 2 }};margin:1px 4px;overflow:hidden;z-index:1">
                                <strong>{{ $e['start']->format('H:i') }}–{{ $e['end']->format('H:i') }} {{ $e['title'] }}</strong>
                                <span>{{ $e['subtitle'] }}</span>
                            </a>
                        @else
                            <div class="aw-ev aw-ev--block" style="grid-row:{{ $startRow }} / span {{ $span }};grid-column:{{ $col + 2 }};margin:1px 4px;overflow:hidden;z-index:1">
                                <strong>{{ $e['start']->format('H:i') }}–{{ $e['end']->format('H:i') }} {{ $e['title'] }}</strong>
                                <span>{{ $e['subtitle'] }}</span>
                                @if ($canManageBlocks)
                                    <span class="aw-row" style="margin-top:.25rem">
                                        <button type="button" class="aw-link" wire:click="removeBlock({{ $e['id'] }})" wire:confirm="Elimini această blocare?">Elimină</button>
                                        @if ($e['series'] ?? false)<button type="button" class="aw-link" wire:click="removeBlock({{ $e['id'] }}, true)" wire:confirm="Elimini această blocare și pe cele următoare din serie?">Elimină seria de aici</button>@endif
                                    </span>
                                @endif
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @elseif ($mode === 'week')
            <div style="overflow-x:auto">
                <div class="aw-cal-grid" style="grid-template-columns: repeat(7, minmax(10rem, 1fr)); min-width: 70rem">
                    @foreach ($days as $day)
                        <button type="button" class="aw-cal-head" style="text-align:left{{ $day === $todayDate ? ';color:var(--primary-600)' : '' }}" wire:click="goToDay('{{ $day }}')">{{ $fmtDay($day) }}
                            @if ($windows[$day])<span class="aw-muted"> · {{ $windows[$day]['open']->format('H:i') }}–{{ $windows[$day]['close']->format('H:i') }}</span>@else<span class="aw-muted"> · închis</span>@endif
                        </button>
                    @endforeach
                    @foreach ($days as $day)
                        <div class="aw-cal-cell {{ $windows[$day] ? '' : 'aw-cal-closed' }}" style="min-height:12rem">
                            @foreach (array_filter($events, fn ($e) => $e['start']->toDateString() === $day) as $e)
                                @if ($e['url'])
                                    <a href="{{ $e['url'] }}" class="aw-ev aw-ev--{{ $e['status'] }}"><strong>{{ $e['start']->format('H:i') }} {{ $e['title'] }}</strong><span>{{ $e['subtitle'] }}</span></a>
                                @else
                                    <div class="aw-ev aw-ev--block"><strong>{{ $e['start']->format('H:i') }}–{{ $e['end']->format('H:i') }} {{ $e['title'] }}</strong><span>{{ $e['subtitle'] }}</span></div>
                                @endif
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            <div class="aw-cal-grid" style="grid-template-columns: repeat(7, minmax(0, 1fr))">
                @foreach (array_slice($dayNames, 1) as $name)
                    <div class="aw-cal-head">{{ $name }}</div>
                @endforeach
                @foreach ($days as $day)
                    @php
                        $dayEvents = array_values(array_filter($events, fn ($e) => $e['start']->toDateString() === $day));
                        $inMonth = \Carbon\CarbonImmutable::parse($day)->month === $current->month;
                    @endphp
                    <div class="aw-cal-cell {{ $windows[$day] ? '' : 'aw-cal-closed' }}" style="min-height:6.5rem;{{ $inMonth ? '' : 'opacity:.55' }}">
                        <button type="button" class="aw-link" wire:click="goToDay('{{ $day }}')" style="font-weight:600{{ $day === $todayDate ? ';color:var(--primary-600)' : '' }}" aria-label="Deschide ziua {{ $fmtDay($day) }}">{{ \Carbon\CarbonImmutable::parse($day)->format('j') }}</button>
                        @foreach (array_slice($dayEvents, 0, 3) as $e)
                            @if ($e['url'])
                                <a href="{{ $e['url'] }}" class="aw-ev aw-ev--{{ $e['status'] }}" style="padding:.125rem .375rem"><strong style="font-weight:500">{{ $e['start']->format('H:i') }} {{ \Illuminate\Support\Str::limit($e['title'], 22) }}</strong></a>
                            @else
                                <div class="aw-ev aw-ev--block" style="padding:.125rem .375rem"><strong style="font-weight:500">{{ $e['start']->format('H:i') }} {{ $e['title'] }}</strong></div>
                            @endif
                        @endforeach
                        @if (count($dayEvents) > 3)
                            <button type="button" class="aw-link aw-muted" wire:click="goToDay('{{ $day }}')">+{{ count($dayEvents) - 3 }} altele</button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($requests->isNotEmpty())
            <section class="aw-card">
                <h3 class="aw-h">Cereri neconfirmate (nu ocupă arena)</h3>
                <table class="aw-table">
                    <thead><tr><th>Primită</th><th>Client</th><th>Pachet</th><th>Dată dorită</th><th>Pers.</th></tr></thead>
                    <tbody>
                        @foreach ($requests as $req)
                            <tr>
                                <td>{{ \App\Support\LocalTime::format($req->created_at, 'd.m H:i') }}</td>
                                <td><a class="aw-link" href="{{ \App\Filament\Resources\Bookings\BookingResource::getUrl('view', ['record' => $req]) }}">{{ $req->contact_name }}</a></td>
                                <td>{{ $req->package?->getTranslation('name', 'ro') }}</td>
                                <td>{{ $req->starts_at ? \App\Support\LocalTime::format($req->starts_at) : '—' }}</td>
                                <td>{{ $req->participants }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif
    </div>
</x-filament-panels::page>
