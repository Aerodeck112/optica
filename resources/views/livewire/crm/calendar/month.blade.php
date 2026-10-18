@php($gridStart = $from->startOfWeek(\Carbon\CarbonImmutable::MONDAY))
@php($gridEnd = $to->endOfWeek(\Carbon\CarbonImmutable::SUNDAY))
<div class="panel overflow-hidden">
    <div class="grid grid-cols-7 border-b border-line bg-paper/60 text-xs font-medium text-muted">
        @foreach (range(1, 7) as $wd)<div class="px-2 py-2">{{ mb_substr(__('crm.weekdays.'.$wd), 0, 3) }}</div>@endforeach
    </div>
    <div class="grid grid-cols-7">
        @for ($d = $gridStart; $d <= $gridEnd; $d = $d->addDay())
            @php($key = $d->toDateString())
            @php($count = $monthCounts[$key] ?? 0)
            <button type="button" wire:click="openDay('{{ $key }}')" wire:key="m-{{ $key }}"
                    @class(['min-h-20 border-b border-r border-line p-2 text-left align-top hover:bg-mist/50', 'text-muted/60' => $d->month !== $day->month, 'bg-mist' => $key === $todayLocal])>
                <span class="block text-sm font-semibold">{{ $d->day }}</span>
                @if ($count)<span class="mt-1 block text-xs text-ink">{{ trans_choice('calendar.appointments_count', $count, ['count' => $count]) }}</span>@endif
            </button>
        @endfor
    </div>
</div>
