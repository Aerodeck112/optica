<div class="space-y-6">
    @can('communications.send')
        <form wire:submit="scheduleRecall" class="panel space-y-4 p-5">
            <h2 class="text-base font-semibold">{{ __('messages.patient.recall') }}</h2>
            <p class="text-xs text-muted">{{ __('messages.patient.recall_hint') }}</p>
            <div aria-live="polite">@if ($notice)<p class="rounded-lg bg-ok-bg px-3 py-2 text-sm text-ok" role="status">{{ $notice }}</p>@endif</div>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-admin.text model="recallDate" type="date" :label="__('messages.patient.recall_date')" required />
                <div>
                    <label for="rc-loc" class="field-label">{{ __('crm.fields.location') }}</label>
                    <select id="rc-loc" wire:model="recallLocation" class="input">
                        <option value="">—</option>
                        @foreach ($locations as $l)<option value="{{ $l->id }}">{{ $l->name() }}</option>@endforeach
                    </select>
                    @error('recallLocation')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            </div>
            <button type="submit" class="btn-primary">{{ __('messages.patient.recall') }}</button>
        </form>
    @endcan
    <livewire:crm.message-log :patient-id="$patientId" :key="'msglog-'.$patientId" />
</div>
