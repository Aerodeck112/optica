@include('livewire.crm.calendar.panel-header', ['title' => $selected?->patient?->fullName() ?? $selected?->contact_name ?? __('calendar.show.title')])
@if ($selected)
    @php
        $s = $selected;
        $local = $s->starts_at->setTimezone($tz);
        $active = ! in_array($s->status, [\App\Enums\AppointmentStatus::Cancelled, \App\Enums\AppointmentStatus::NoShow, \App\Enums\AppointmentStatus::Expired, \App\Enums\AppointmentStatus::Completed], true);
    @endphp
    <div class="space-y-6 text-sm">
        <div class="flex flex-wrap items-center gap-2">
            <x-appointment-status :status="$s->status" />
            <span class="badge-neutral">{{ __('appointments.source.'.$s->source->value) }}</span>
        </div>
        <dl class="grid grid-cols-[7rem_1fr] gap-x-3 gap-y-2">
            <dt class="text-muted">{{ __('calendar.show.when') }}</dt>
            <dd class="font-medium text-heading">{{ ucfirst($local->locale(app()->getLocale())->isoFormat('dddd, D MMM YYYY')) }}, {{ $local->format('H:i') }}–{{ $s->ends_at->setTimezone($tz)->format('H:i') }}</dd>
            <dt class="text-muted">{{ __('crm.fields.service') }}</dt><dd>{{ $s->service->name() }}</dd>
            <dt class="text-muted">{{ __('crm.fields.specialist') }}</dt><dd>{{ $s->specialist->display_name }}</dd>
            <dt class="text-muted">{{ __('calendar.create.phone') }}</dt>
            <dd>@php($phone = $s->patient?->phone ?? $s->contact_phone)@if ($phone)<a class="btn-link" href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}">{{ $phone }}</a>@else — @endif</dd>
            @if ($s->patient)<dt class="text-muted">{{ __('crm.fields.patient') }}</dt><dd>{{ $s->patient->code }}</dd>@endif
            <dt class="text-muted">{{ __('calendar.create.locale') }}</dt><dd>{{ __('crm.languages.'.$s->locale) }}</dd>
            <dt class="text-muted">{{ __('calendar.show.created_by') }}</dt>
            <dd>{{ $s->creator?->name ?? ($s->source === \App\Enums\AppointmentSource::Online ? __('calendar.show.online_patient') : __('calendar.events.system')) }}, {{ $s->created_at->setTimezone($tz)->format('d.m.Y H:i') }}</dd>
            @if ($s->admin_notes)<dt class="text-muted">{{ __('calendar.show.notes') }}</dt><dd class="whitespace-pre-line">{{ $s->admin_notes }}</dd>@endif
        </dl>

        @if ($s->status === \App\Enums\AppointmentStatus::Cancelled)
            <p class="rounded-lg bg-bad-bg px-3 py-2 text-bad">{{ __('calendar.show.cancelled_info', [
                'name' => $s->events->firstWhere('type', 'cancelled')?->user?->name ?? __('calendar.events.patient'),
                'date' => $s->cancelled_at?->setTimezone($tz)->format('d.m.Y H:i'),
                'reason' => __('calendar.cancel_reasons.'.$s->cancel_reason).($s->cancel_note ? ' – '.$s->cancel_note : ''),
            ]) }}</p>

            @php($matches = $waitlistEntries->filter(fn ($w) => (! $w->service_id || $w->service_id === $s->service_id) && (! $w->specialist_id || $w->specialist_id === $s->specialist_id) && $w->preferred_from->toDateString() <= $local->toDateString() && $w->preferred_to->toDateString() >= $local->toDateString()))
            @if ($matches->isNotEmpty() && $canCreate)
                <section aria-labelledby="wl-matches">
                    <h3 id="wl-matches" class="font-semibold">{{ __('calendar.show.waitlist_matches') }}</h3>
                    <ul class="mt-2 space-y-2">
                        @foreach ($matches as $w)
                            <li class="flex items-center gap-2 rounded-lg bg-mist px-3 py-2">
                                <span class="flex-1">{{ $w->displayName() }} · {{ $w->phone() }}</span>
                                <button type="button" class="btn-link" wire:click="bookFromWaitlist({{ $w->id }})">{{ __('calendar.show.book_for') }}</button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endif

        @error('status')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        @php($transitions = array_filter($s->status->allowedTransitions(), fn ($t) => ! in_array($t, [\App\Enums\AppointmentStatus::Cancelled, \App\Enums\AppointmentStatus::Expired], true)))
        @if ($transitions && auth()->user()->can('appointments.change_status', $s->location_id))
            <section aria-labelledby="st-actions">
                <h3 id="st-actions" class="font-semibold">{{ __('calendar.show.status_actions') }}</h3>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($transitions as $t)
                        <button type="button" class="btn-secondary" wire:click="changeStatus('{{ $t->value }}')">{{ __('calendar.actions.'.$t->value) }}</button>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($active && $canUpdate)
            <details class="rounded-lg ring-1 ring-line" @if ($errors->has('reschedule.*')) open @endif>
                <summary class="cursor-pointer px-4 py-3 font-semibold text-ink">{{ __('calendar.show.move') }}</summary>
                <form wire:submit="applyReschedule" class="space-y-3 px-4 pb-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="r-date" class="field-label">{{ __('calendar.create.date') }}</label>
                            <input id="r-date" type="date" wire:model="reschedule.date" class="input">
                        </div>
                        <div>
                            <label for="r-time" class="field-label">{{ __('calendar.create.time') }}</label>
                            <input id="r-time" type="time" step="300" wire:model="reschedule.time" class="input">
                        </div>
                    </div>
                    <div>
                        <label for="r-sp" class="field-label">{{ __('crm.fields.specialist') }}</label>
                        <select id="r-sp" wire:model="reschedule.specialist_id" class="input">
                            @foreach ($rescheduleSpecialists as $rs)<option value="{{ $rs->id }}">{{ $rs->display_name }}</option>@endforeach
                        </select>
                    </div>
                    @error('reschedule.time')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    @error('reschedule.specialist_id')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    <button type="submit" class="btn-primary w-full">{{ __('calendar.show.move_submit') }}</button>
                </form>
            </details>
        @endif

        @if ($active && auth()->user()->can('appointments.cancel', $s->location_id))
            <details class="rounded-lg ring-1 ring-line" @if ($errors->has('cancel.*')) open @endif>
                <summary class="cursor-pointer px-4 py-3 font-semibold text-bad">{{ __('calendar.show.cancel') }}</summary>
                <form wire:submit="cancelAppointment" class="space-y-3 px-4 pb-4">
                    <div>
                        <label for="x-reason" class="field-label">{{ __('calendar.show.cancel_reason') }}</label>
                        <select id="x-reason" wire:model="cancel.reason" class="input">
                            @foreach (\App\Livewire\Crm\Calendar::CANCEL_REASONS as $r)<option value="{{ $r }}">{{ __('calendar.cancel_reasons.'.$r) }}</option>@endforeach
                        </select>
                        @error('cancel.reason')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="x-note" class="field-label">{{ __('calendar.show.cancel_note') }}</label>
                        <input id="x-note" type="text" wire:model="cancel.note" class="input" maxlength="500">
                        @error('cancel.note')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="btn-danger w-full">{{ __('calendar.show.cancel_submit') }}</button>
                </form>
            </details>
        @endif

        <section aria-labelledby="hist">
            <h3 id="hist" class="font-semibold">{{ __('calendar.show.history') }}</h3>
            <ol class="mt-2 space-y-2 border-l-2 border-line pl-4">
                @foreach ($s->events->sortByDesc('id') as $ev)
                    <li>
                        <p class="text-heading">
                            @switch($ev->type)
                                @case('status_changed') {{ __('calendar.events.status_changed', ['from' => $ev->from_status ? __('appointments.status.'.$ev->from_status) : '—', 'to' => __('appointments.status.'.$ev->to_status)]) }} @break
                                @case('rescheduled') {{ __('calendar.events.rescheduled', ['from' => $ev->old_starts_at?->setTimezone($tz)->format('d.m H:i')]) }} @break
                                @default {{ __('calendar.events.'.$ev->type) }}
                            @endswitch
                        </p>
                        <p class="text-xs text-muted">{{ $ev->created_at->setTimezone($tz)->format('d.m.Y H:i') }} · {{ $ev->user?->name ?? __('calendar.events.'.($ev->actor === 'patient' ? 'patient' : 'system')) }}</p>
                    </li>
                @endforeach
            </ol>
        </section>
    </div>
@endif
