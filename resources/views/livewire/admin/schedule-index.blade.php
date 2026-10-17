<div class="max-w-6xl space-y-6">
    <div class="flex flex-wrap items-end gap-4">
        <div>
            <label for="f-specialist" class="field-label">{{ __('crm.fields.specialist') }}</label>
            <select id="f-specialist" wire:model.live="specialist" class="input">
                <option value="">{{ __('crm.all') }}</option>
                @foreach ($specialists as $s)<option value="{{ $s->id }}">{{ $s->display_name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="f-location" class="field-label">{{ __('crm.fields.location') }}</label>
            <select id="f-location" wire:model.live="location" class="input">
                <option value="">{{ __('crm.all') }}</option>
                @foreach ($locations as $l)<option value="{{ $l->id }}">{{ $l->name() }}</option>@endforeach
            </select>
        </div>
        <div class="ml-auto flex flex-wrap gap-3">
            @can('schedules.manage')
                <a href="{{ route('admin.exceptions.index') }}" wire:navigate class="btn-secondary">{{ __('catalog.exceptions.title') }}</a>
                <a href="{{ route('admin.schedules.create', array_filter(['specialist_id' => $specialist, 'location_id' => $location])) }}" wire:navigate class="btn-primary">{{ __('catalog.schedules.create') }}</a>
            @endcan
        </div>
    </div>

    <x-admin.affected-appointments :appointments="$affected" />

    <div class="panel overflow-x-auto">
        <table class="table-base table-stack">
            <thead>
            <tr>
                <th scope="col">{{ __('catalog.schedules.schedule') }}</th>
                <th scope="col">{{ __('crm.fields.location') }}</th>
                <th scope="col">{{ __('catalog.schedules.valid') }}</th>
                <th scope="col">{{ __('catalog.schedules.weekly_hours') }}</th>
                <th scope="col"><span class="sr-only">{{ __('crm.actions.actions') }}</span></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($schedules as $sch)
                @php($minutes = $sch->segments->sum(fn ($s) => (strtotime($s->end_time) - strtotime($s->start_time)) / 60) / max(1, $sch->cycle_weeks))
                <tr wire:key="sch-{{ $sch->id }}">
                    <td>
                        <p class="font-medium text-heading">{{ $sch->specialist->display_name }}</p>
                        <p class="text-xs text-muted">{{ $sch->name }}@if ($sch->cycle_weeks > 1) · {{ __('catalog.schedules.alternating', ['weeks' => $sch->cycle_weeks]) }}@endif</p>
                    </td>
                    <td data-label="{{ __('crm.fields.location') }}">{{ $sch->location->name() }}</td>
                    <td data-label="{{ __('catalog.schedules.valid') }}" class="whitespace-nowrap">
                        {{ $sch->valid_from->format('d.m.Y') }} – {{ $sch->valid_to?->format('d.m.Y') ?? __('catalog.schedules.open_ended') }}
                        @unless ($sch->is_active)<span class="badge-bad ml-1">{{ __('crm.state.inactive') }}</span>@endunless
                    </td>
                    <td data-label="{{ __('catalog.schedules.weekly_hours') }}">{{ __('catalog.schedules.hours', ['hours' => rtrim(rtrim(number_format($minutes / 60, 1, ',', ''), '0'), ',')]) }}</td>
                    <td class="cell-actions text-right">
                        @can('schedules.manage', $sch->location_id)
                            <a href="{{ route('admin.schedules.edit', $sch) }}" wire:navigate class="btn-link text-sm">{{ __('crm.actions.edit') }}</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-10 text-center text-muted">{{ __('catalog.schedules.empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
