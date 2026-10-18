@php($list = $appointments->filter(fn ($a) => \Carbon\CarbonImmutable::instance($a->starts_at)->setTimezone($tz)->toDateString() === $day->toDateString()))
@if ($list->isEmpty())
    <p class="text-sm text-muted">{{ __('calendar.no_appointments') }}</p>
@else
    <ol class="panel divide-y divide-line">
        @foreach ($list as $a)
            <li wire:key="ag-{{ $a->id }}">
                <button type="button" wire:click="show({{ $a->id }})" class="flex w-full gap-4 px-4 py-3 text-left hover:bg-mist/40">
                    <span class="w-14 shrink-0 font-semibold text-heading">{{ $a->starts_at->setTimezone($tz)->format('H:i') }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium text-heading">{{ $a->patient?->fullName() ?? $a->contact_name }}</span>
                        <span class="block text-sm text-muted">{{ $a->service->name() }} · {{ $a->specialist->display_name }}</span>
                    </span>
                    <span class="shrink-0 self-start"><x-appointment-status :status="$a->status" /></span>
                </button>
            </li>
        @endforeach
    </ol>
@endif
