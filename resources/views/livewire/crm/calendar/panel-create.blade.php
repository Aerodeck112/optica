@include('livewire.crm.calendar.panel-header', ['title' => __('calendar.create.title')])
<form wire:submit="create" class="space-y-4" data-preserve>
    <div>
        <label for="c-specialist" class="field-label">{{ __('calendar.create.specialist') }}</label>
        <select id="c-specialist" wire:model.live="form.specialist_id" class="input">
            @foreach ($specialists as $s)<option value="{{ $s->id }}">{{ $s->display_name }}</option>@endforeach
        </select>
    </div>
    <div>
        <label for="c-service" class="field-label">{{ __('calendar.create.service') }}</label>
        @if ($formOfferings->isEmpty())
            <p class="text-sm text-warn">{{ __('calendar.create.no_services') }}</p>
        @else
            <select id="c-service" wire:model="form.service_id" class="input" @error('form.service_id') aria-invalid="true" @enderror>
                @foreach ($formOfferings as $o)<option value="{{ $o->service_id }}">{{ $o->service->name() }} ({{ __('crm.minutes', ['count' => $o->durationMinutes()]) }})</option>@endforeach
            </select>
        @endif
        @error('form.service_id')<p class="field-error">{{ $message }}</p>@enderror
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="c-date" class="field-label">{{ __('calendar.create.date') }}</label>
            <input id="c-date" type="date" wire:model="form.date" class="input">
        </div>
        <div>
            <label for="c-time" class="field-label">{{ __('calendar.create.time') }}</label>
            <input id="c-time" type="time" step="300" wire:model="form.time" class="input" @error('form.time') aria-invalid="true" aria-describedby="c-time-error" @enderror>
        </div>
        @error('form.time')<p id="c-time-error" class="field-error col-span-2" role="alert">{{ $message }}</p>@enderror
    </div>

    <fieldset class="space-y-3">
        <legend class="field-label">{{ __('calendar.create.patient') }}</legend>
        @if ($chosenPatient)
            <div class="flex items-center gap-3 rounded-lg bg-mist px-3 py-2">
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-heading">{{ $chosenPatient->fullName() }}</p>
                    <p class="text-xs text-muted">{{ $chosenPatient->code }} · {{ $chosenPatient->phone }}@if ($chosenPatient->birth_date) · {{ $chosenPatient->birth_date->format('Y') }}@endif</p>
                </div>
                <button type="button" class="btn-link text-sm" wire:click="clearPatient">{{ __('calendar.create.change') }}</button>
            </div>
        @elseif ($newPatient)
            <div class="grid grid-cols-2 gap-3">
                <x-admin.text model="form.last_name" :label="__('calendar.create.last_name')" required />
                <x-admin.text model="form.first_name" :label="__('calendar.create.first_name')" required />
                <x-admin.text model="form.phone" type="tel" :label="__('calendar.create.phone')" required />
                <x-admin.text model="form.email" type="email" :label="__('calendar.create.email')" />
            </div>
            @if ($duplicates->isNotEmpty())
                <div class="rounded-lg bg-warn-bg p-3 text-sm" role="note">
                    <p class="font-medium text-warn">{{ __('calendar.create.duplicates') }}</p>
                    <ul class="mt-2 space-y-1">
                        @foreach ($duplicates as $dup)
                            <li class="flex items-center gap-2">
                                <span class="flex-1">{{ $dup->fullName() }} · {{ $dup->phone }}</span>
                                <button type="button" class="btn-link" wire:click="choosePatient({{ $dup->id }})">{{ __('calendar.create.use_existing') }}</button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <button type="button" class="btn-link text-sm" wire:click="$set('newPatient', false)">{{ __('calendar.create.contact_only') }}</button>
        @else
            <div>
                <label for="c-search" class="sr-only">{{ __('calendar.create.search') }}</label>
                <input id="c-search" type="search" wire:model.live.debounce.300ms="patientQuery" class="input" placeholder="{{ __('calendar.create.search') }}" autocomplete="off" aria-describedby="c-search-hint">
                <p id="c-search-hint" class="field-hint">{{ __('calendar.create.search_hint') }}</p>
                @if (mb_strlen($patientQuery) >= 2)
                    <ul class="mt-2 divide-y divide-line rounded-lg ring-1 ring-line" aria-live="polite">
                        @forelse ($patientResults as $p)
                            <li><button type="button" class="w-full px-3 py-2 text-left text-sm hover:bg-mist" wire:click="choosePatient({{ $p->id }})">
                                <span class="font-medium text-heading">{{ $p->fullName() }}</span>
                                <span class="block text-xs text-muted">{{ $p->code }} · {{ $p->phone }}@if ($p->birth_date) · {{ $p->birth_date->format('Y') }}@endif</span>
                            </button></li>
                        @empty
                            <li class="px-3 py-2 text-sm text-muted">{{ __('calendar.create.no_results') }}</li>
                        @endforelse
                    </ul>
                @endif
            </div>
            <button type="button" class="btn-secondary w-full" wire:click="$set('newPatient', true)">{{ __('calendar.create.new_patient') }}</button>
            <p class="text-xs text-muted">{{ __('calendar.create.contact_only') }}</p>
            <div class="grid grid-cols-2 gap-3">
                <x-admin.text model="form.contact_name" :label="__('calendar.create.contact_name')" />
                <x-admin.text model="form.contact_phone" type="tel" :label="__('calendar.create.contact_phone')" />
            </div>
        @endif
    </fieldset>

    <div>
        <label for="c-locale" class="field-label">{{ __('calendar.create.locale') }}</label>
        <select id="c-locale" wire:model="form.locale" class="input">
            @foreach (config('optica.locales') as $l)<option value="{{ $l }}">{{ __('crm.languages.'.$l) }}</option>@endforeach
        </select>
    </div>
    <div>
        <label for="c-notes" class="field-label">{{ __('calendar.create.notes') }}</label>
        <textarea id="c-notes" wire:model="form.notes" rows="2" class="input"></textarea>
    </div>
    @if ($canOverride)
        <x-admin.toggle model="form.outside_schedule" :label="__('calendar.create.outside_schedule')" />
    @endif
    <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled" @disabled($formOfferings->isEmpty())>{{ __('calendar.create.submit') }}</button>
</form>
