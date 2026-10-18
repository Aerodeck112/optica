<div class="space-y-6">
    @if ($canCreate)
        <form wire:submit="start" class="panel space-y-4 p-5">
            <h2 class="text-base font-semibold">{{ __('patients.records.new') }}</h2>
            @if ($templates->isEmpty())
                <p class="rounded-lg bg-warn-bg px-4 py-3 text-sm text-warn" role="status">{{ __('patients.records.no_validated_template') }}</p>
            @else
                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <label for="tpl" class="field-label">{{ __('patients.records.template') }}</label>
                        <select id="tpl" wire:model="templateId" class="input">
                            @foreach ($templates as $t)<option value="{{ $t->id }}">{{ $t->label() }} (v{{ $t->version }})</option>@endforeach
                        </select>
                        @error('templateId')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="appt" class="field-label">{{ __('patients.records.appointment') }}</label>
                        <select id="appt" wire:model.live="appointmentId" class="input">
                            <option value="">{{ __('patients.records.no_appointment') }}</option>
                            @foreach ($appointments as $a)<option value="{{ $a->id }}">{{ $a->starts_at->timezone($tz)->format('d.m.Y H:i') }} · {{ $a->service?->name() }}</option>@endforeach
                        </select>
                    </div>
                    @if (! $appointmentId)
                        <div>
                            <label for="loc" class="field-label">{{ __('crm.fields.location') }}</label>
                            <select id="loc" wire:model="locationId" class="input" @error('locationId') aria-invalid="true" @enderror>
                                <option value="">—</option>
                                @foreach ($locations as $l)<option value="{{ $l->id }}">{{ $l->name() }}</option>@endforeach
                            </select>
                            @error('locationId')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    @endif
                </div>
                <button type="submit" class="btn-primary">{{ __('patients.records.start') }}</button>
            @endif
        </form>
    @elseif (auth()->user()->can('medical_records.create') && ! $hasProfile)
        <p class="rounded-lg bg-mist/60 px-4 py-3 text-sm text-ink">{{ __('clinical.errors.no_specialist_profile') }}</p>
    @endif

    <div class="panel overflow-x-auto">
        <table class="table-base table-stack">
            <thead><tr>
                <th scope="col">{{ __('patients.records.date') }}</th>
                <th scope="col">{{ __('patients.records.template') }}</th>
                <th scope="col">{{ __('crm.fields.specialist') }}</th>
                <th scope="col">{{ __('crm.fields.location') }}</th>
                <th scope="col">{{ __('crm.fields.status') }}</th>
                <th scope="col"><span class="sr-only">{{ __('crm.actions.actions') }}</span></th>
            </tr></thead>
            <tbody>
            @forelse ($records as $r)
                <tr wire:key="r-{{ $r->id }}">
                    <td class="whitespace-nowrap">{{ $r->examined_at->timezone($tz)->format('d.m.Y H:i') }}</td>
                    <td data-label="{{ __('patients.records.template') }}">{{ $r->template?->label() }}</td>
                    <td data-label="{{ __('crm.fields.specialist') }}">{{ $r->specialist?->display_name }}</td>
                    <td data-label="{{ __('crm.fields.location') }}">{{ $r->location?->name() }}</td>
                    <td data-label="{{ __('crm.fields.status') }}"><span class="{{ $r->isFinal() ? 'badge-ok' : 'badge-warn' }}">{{ __('clinical.status.'.$r->status) }}</span></td>
                    <td class="cell-actions text-right"><a href="{{ route('crm.records.edit', $r) }}" wire:navigate class="btn-link text-sm">{{ __('patients.records.open') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-8 text-center text-muted">{{ __('patients.records.none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
