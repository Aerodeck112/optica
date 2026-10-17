<div class="max-w-6xl space-y-8">
    <x-admin.affected-appointments :appointments="$affected" />

    <form wire:submit="save" data-preserve class="panel space-y-5 p-5" aria-labelledby="exc-form-title">
        <h2 id="exc-form-title" class="text-base font-semibold">{{ $editingId ? __('catalog.exceptions.edit') : __('catalog.exceptions.create') }}</h2>
        <div class="grid gap-5 md:grid-cols-3">
            <div>
                <label for="kind" class="field-label">{{ __('catalog.exceptions.kind') }}</label>
                <select id="kind" wire:model.live="kind" class="input">
                    <option value="unavailable">{{ __('catalog.exceptions.kinds.unavailable') }}</option>
                    <option value="available">{{ __('catalog.exceptions.kinds.available') }}</option>
                </select>
                @error('kind')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="reason" class="field-label">{{ __('catalog.exceptions.reason') }}</label>
                <select id="reason" wire:model="reason" class="input">
                    @foreach (\App\Models\ScheduleException::REASONS as $r)<option value="{{ $r }}">{{ __('catalog.exceptions.reasons.'.$r) }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="ex-specialist" class="field-label">{{ __('crm.fields.specialist') }}</label>
                <select id="ex-specialist" wire:model="specialist_id" class="input">
                    <option value="">{{ __('catalog.exceptions.everyone') }}</option>
                    @foreach ($specialists as $s)<option value="{{ $s->id }}">{{ $s->display_name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="ex-location" class="field-label">{{ __('crm.fields.location') }}</label>
                <select id="ex-location" wire:model="location_id" class="input">
                    @if ($canGlobal)<option value="">{{ __('catalog.exceptions.all_locations') }}</option>@endif
                    @foreach ($locations as $l)<option value="{{ $l->id }}">{{ $l->name() }}</option>@endforeach
                </select>
            </div>
            <x-admin.text model="starts_on" type="date" :label="__('catalog.exceptions.from')" required />
            <x-admin.text model="ends_on" type="date" :label="__('catalog.exceptions.to')" required />
            <x-admin.text model="start_time" type="time" :label="__('catalog.exceptions.from_time')" :hint="__('catalog.exceptions.time_hint')" />
            <x-admin.text model="end_time" type="time" :label="__('catalog.exceptions.to_time')" />
            @if ($kind === 'available')
                <div class="pt-7"><x-admin.toggle model="online_enabled" :label="__('catalog.exceptions.online')" /></div>
            @endif
            <div class="md:col-span-3">
                <label for="description" class="field-label">{{ __('catalog.exceptions.note') }}</label>
                <input id="description" type="text" wire:model="description" class="input" maxlength="500">
                <p class="field-hint">{{ __('catalog.exceptions.note_hint') }}</p>
            </div>
        </div>
        <div class="flex flex-wrap gap-3">
            <button type="submit" class="btn-primary">{{ $editingId ? __('crm.actions.save') : __('catalog.exceptions.add') }}</button>
            @if ($editingId)<button type="button" class="btn-secondary" wire:click="cancel">{{ __('crm.actions.cancel') }}</button>@endif
        </div>
    </form>

    <div>
        <div class="flex flex-wrap items-end gap-4">
            <h2 class="text-base font-semibold">{{ __('catalog.exceptions.list') }}</h2>
            <div class="ml-auto">
                <label for="period" class="sr-only">{{ __('catalog.exceptions.period') }}</label>
                <select id="period" wire:model.live="period" class="input">
                    <option value="upcoming">{{ __('catalog.exceptions.periods.upcoming') }}</option>
                    <option value="past">{{ __('catalog.exceptions.periods.past') }}</option>
                    <option value="all">{{ __('crm.all') }}</option>
                </select>
            </div>
        </div>
        <div class="panel mt-4 overflow-x-auto">
            <table class="table-base table-stack">
                <thead>
                <tr>
                    <th scope="col">{{ __('catalog.exceptions.when') }}</th>
                    <th scope="col">{{ __('catalog.exceptions.who') }}</th>
                    <th scope="col">{{ __('crm.fields.location') }}</th>
                    <th scope="col">{{ __('catalog.exceptions.reason') }}</th>
                    <th scope="col"><span class="sr-only">{{ __('crm.actions.actions') }}</span></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($exceptions as $e)
                    <tr wire:key="exc-{{ $e->id }}">
                        <td class="whitespace-nowrap">
                            <p class="font-medium text-heading">{{ $e->starts_on->format('d.m.Y') }}@if ($e->ends_on->ne($e->starts_on)) – {{ $e->ends_on->format('d.m.Y') }}@endif</p>
                            <p class="text-xs text-muted">{{ $e->isFullDay() ? __('catalog.exceptions.full_day') : substr($e->start_time, 0, 5).'–'.substr($e->end_time, 0, 5) }}</p>
                        </td>
                        <td data-label="{{ __('catalog.exceptions.who') }}">{{ $e->specialist?->display_name ?? __('catalog.exceptions.everyone') }}</td>
                        <td data-label="{{ __('crm.fields.location') }}">{{ $e->location?->name() ?? __('catalog.exceptions.all_locations') }}</td>
                        <td data-label="{{ __('catalog.exceptions.reason') }}">
                            <span @class(['badge-ok' => $e->kind === 'available', 'badge-neutral' => $e->kind !== 'available'])>{{ __('catalog.exceptions.reasons.'.$e->reason) }}</span>
                            @if ($e->description)<p class="mt-1 text-xs text-muted">{{ $e->description }}</p>@endif
                        </td>
                        <td class="cell-actions text-right whitespace-nowrap">
                            <button type="button" class="btn-link text-sm" wire:click="edit({{ $e->id }})">{{ __('crm.actions.edit') }}</button>
                            <button type="button" class="btn-link ml-3 text-sm text-bad" wire:click="delete({{ $e->id }})" wire:confirm="{{ __('crm.actions.confirm_delete') }}">{{ __('catalog.resources.delete') }}</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-10 text-center text-muted">{{ __('crm.empty_list') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
