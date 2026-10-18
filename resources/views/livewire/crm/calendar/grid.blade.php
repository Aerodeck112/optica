@php
    $days = $view === 'week' ? collect(range(0, 6))->map(fn ($i) => $from->addDays($i)) : collect([$day]);
    // Day view: one column per specialist. Week view: one column per day for one specialist.
    $cols = collect();
    if ($view === 'week') {
        $c = $columns->first();
        if ($c && $specialistId) {
            foreach ($days as $d) {
                $cols->push(['key' => $d->toDateString(), 'title' => $d->locale(app()->getLocale())->isoFormat('ddd D'), 'specialist' => $c['specialist'], 'date' => $d->toDateString(), 'windows' => $c['windows'][$d->toDateString()] ?? []]);
            }
        }
    } else {
        foreach ($columns as $c) {
            $cols->push(['key' => 's'.$c['specialist']->id, 'title' => $c['specialist']->display_name, 'specialist' => $c['specialist'], 'date' => $day->toDateString(), 'windows' => $c['windows'][$day->toDateString()] ?? []]);
        }
    }
    $minuteOf = fn ($instant) => (int) round((\Carbon\CarbonImmutable::instance($instant)->setTimezone($tz)->getTimestamp() - \Carbon\CarbonImmutable::parse(\Carbon\CarbonImmutable::instance($instant)->setTimezone($tz)->toDateString().' '.sprintf('%02d:00', $startHour), $tz)->getTimestamp()) / 60);
@endphp

@if ($view === 'week' && ! $specialistId)
    <p class="text-sm text-muted">{{ __('calendar.pick_specialist') }}</p>
@elseif ($cols->isEmpty())
    <p class="text-sm text-muted">{{ __('calendar.no_columns') }}</p>
@else
    <p class="mb-2 hidden text-xs text-muted md:block">{{ __('calendar.drag_hint') }}</p>
    <div class="panel overflow-x-auto">
        <div class="grid min-w-full" style="grid-template-columns: 3.5rem repeat({{ $cols->count() }}, minmax(9.5rem, 1fr));">
            {{-- Header --}}
            <div class="sticky left-0 z-10 border-b border-line bg-white"></div>
            @foreach ($cols as $col)
                <div @class(['border-b border-l border-line px-2 py-2 text-sm font-semibold text-heading', 'bg-mist' => $col['date'] === $todayLocal && $view === 'week'])>{{ $col['title'] }}</div>
            @endforeach

            {{-- Time axis --}}
            <div class="sticky left-0 z-10 bg-white" style="height: {{ $gridMinutes * $px }}px">
                @foreach (range($startHour, $endHour - 1) as $h)
                    <div class="relative text-right text-xs text-muted" style="height: {{ 60 * $px }}px"><span class="absolute -top-2 right-2">{{ sprintf('%02d:00', $h) }}</span></div>
                @endforeach
            </div>

            @foreach ($cols as $col)
                @php
                    $sid = $col['specialist']->id;
                    $colAppointments = $appointments->filter(fn ($a) => $a->specialist_id === $sid && \Carbon\CarbonImmutable::instance($a->starts_at)->setTimezone($tz)->toDateString() === $col['date']);
                    $colElsewhere = $elsewhere->filter(fn ($a) => $a->specialist_id === $sid && \Carbon\CarbonImmutable::instance($a->block_starts_at)->setTimezone($tz)->toDateString() === $col['date']);
                @endphp
                <div class="relative border-l border-line bg-sand/60" style="height: {{ $gridMinutes * $px }}px; background-image: repeating-linear-gradient(to bottom, transparent 0, transparent {{ 60 * $px - 1 }}px, var(--color-line) {{ 60 * $px - 1 }}px, var(--color-line) {{ 60 * $px }}px);"
                     wire:key="col-{{ $col['key'] }}">
                    {{-- Working time --}}
                    @foreach ($col['windows'] as $w)
                        @php($top = max(0, $minuteOf($w->start)))
                        @php($height = $w->minutes())
                        <div class="absolute inset-x-0 bg-white" style="top: {{ $top * $px }}px; height: {{ $height * $px }}px" aria-hidden="true"></div>
                        @if ($canCreate)
                            @php($step = max(5, (int) ($w->meta['slot_minutes'] ?? 15)))
                            @for ($m = 0; $m + $step <= $height; $m += $step)
                                @php($slotLocal = $w->start->addMinutes($m)->setTimezone($tz))
                                <button type="button"
                                        class="absolute inset-x-0 z-[1] border-t border-dashed border-line/60 text-left text-[11px] text-transparent hover:bg-mist/70 hover:text-ink focus:bg-mist focus:text-ink"
                                        style="top: {{ ($top + $m) * $px }}px; height: {{ $step * $px }}px"
                                        wire:click="startCreate({{ $sid }}, '{{ $col['date'] }}', '{{ $slotLocal->format('H:i') }}')"
                                        @dragover.prevent @drop.prevent="if (dragging && confirm(@js(__('calendar.move_confirm', ['date' => $col['date'], 'time' => '__T__'])).replace('__T__', '{{ $slotLocal->format('H:i') }}'))) { $wire.moveTo(dragging, {{ $sid }}, '{{ $col['date'] }}', '{{ $slotLocal->format('H:i') }}') }; dragging = null"
                                        aria-label="{{ __('calendar.new_at', ['time' => $slotLocal->format('H:i'), 'name' => $col['specialist']->display_name]) }}">
                                    <span class="pl-1">{{ $slotLocal->format('H:i') }}</span>
                                </button>
                            @endfor
                        @endif
                    @endforeach

                    {{-- Time in another location: no patient data --}}
                    @foreach ($colElsewhere as $e)
                        @php($top = $minuteOf($e->block_starts_at))
                        <div class="absolute inset-x-1 z-[2] overflow-hidden rounded-md bg-line/70 px-2 py-1 text-[11px] text-muted"
                             style="top: {{ $top * $px }}px; height: {{ max(20, (int) round(($e->block_ends_at->getTimestamp() - $e->block_starts_at->getTimestamp()) / 60) * $px) }}px; background-image: repeating-linear-gradient(135deg, transparent 0 6px, rgba(255,255,255,.5) 6px 12px);">
                            {{ __('calendar.elsewhere') }}
                        </div>
                    @endforeach

                    {{-- Appointments --}}
                    @foreach ($colAppointments as $a)
                        @php($top = $minuteOf($a->starts_at))
                        @php($minutes = (int) round(($a->ends_at->getTimestamp() - $a->starts_at->getTimestamp()) / 60))
                        @php($inactive = in_array($a->status, [\App\Enums\AppointmentStatus::Cancelled, \App\Enums\AppointmentStatus::NoShow, \App\Enums\AppointmentStatus::Expired], true))
                        <button type="button" wire:key="appt-{{ $a->id }}" wire:click="show({{ $a->id }})"
                                @if ($canUpdate && ! $inactive) draggable="true" @dragstart="dragging = {{ $a->id }}" @dragend="dragging = null" @endif
                                @class([
                                    'absolute inset-x-1 z-[3] overflow-hidden rounded-md border-l-4 bg-white px-2 py-1 text-left text-xs shadow-sm ring-1 ring-line hover:ring-ink',
                                    'opacity-60 line-through' => $inactive,
                                    'ring-2 ring-ink' => $selectedId === $a->id,
                                ])
                                style="top: {{ $top * $px }}px; height: {{ max(24, $minutes * $px - 2) }}px; border-left-color: {{ $a->service->color ?? '#5d9fcf' }}">
                            <span class="block font-semibold text-heading">{{ $a->starts_at->setTimezone($tz)->format('H:i') }}–{{ $a->ends_at->setTimezone($tz)->format('H:i') }} {{ $a->patient?->fullName() ?? $a->contact_name }}</span>
                            <span class="block truncate text-muted">{{ $a->service->name() }}</span>
                            @if ($minutes >= 30)<span class="mt-0.5 inline-block no-underline"><x-appointment-status :status="$a->status" /></span>@endif
                        </button>
                    @endforeach

                    {{-- Now line --}}
                    @if ($col['date'] === $todayLocal)
                        @php($nowMin = $minuteOf(now()))
                        @if ($nowMin >= 0 && $nowMin <= $gridMinutes)
                            <div class="pointer-events-none absolute inset-x-0 z-[4] border-t-2 border-bad" style="top: {{ $nowMin * $px }}px"><span class="sr-only">{{ __('calendar.now') }}</span></div>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif
