<div class="grid max-w-6xl gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
    <div class="space-y-6">
        <div aria-live="polite">
            @if ($notice)<div class="rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ $notice }}</div>@endif
        </div>
        <section class="panel p-5" aria-labelledby="lead-title">
            <div class="flex flex-wrap items-center gap-3">
                <h2 id="lead-title" class="flex-1 text-base font-semibold">{{ __('leads.types.'.$lead->type) }}</h2>
                <a href="{{ route('crm.leads.index') }}" wire:navigate class="btn-link text-sm">{{ __('crm.actions.back_to_list') }}</a>
            </div>
            <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-xs text-muted">{{ __('crm.fields.name') }}</dt><dd>{{ $lead->name }}</dd></div>
                <div><dt class="text-xs text-muted">{{ __('crm.fields.phone') }}</dt><dd>@if ($lead->phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $lead->phone) }}" class="btn-link">{{ $lead->phone }}</a>@else — @endif</dd></div>
                <div><dt class="text-xs text-muted">{{ __('crm.fields.email') }}</dt><dd>@if ($lead->email)<a href="mailto:{{ $lead->email }}" class="btn-link">{{ $lead->email }}</a>@else — @endif</dd></div>
                <div><dt class="text-xs text-muted">{{ __('leads.language') }}</dt><dd>{{ __('crm.languages.'.$lead->locale) }}</dd></div>
                @if ($lead->company)<div><dt class="text-xs text-muted">{{ __('leads.company') }}</dt><dd>{{ $lead->company }}</dd></div>@endif
                <div><dt class="text-xs text-muted">{{ __('crm.fields.location') }}</dt><dd>{{ $lead->location?->name() ?? '—' }}</dd></div>
                <div><dt class="text-xs text-muted">{{ __('leads.source') }}</dt><dd class="break-all">{{ $lead->source ?? '—' }}@if ($lead->utm) <span class="text-xs text-muted">({{ collect($lead->utm)->map(fn ($v, $k) => $k.'='.$v)->join(', ') }})</span>@endif</dd></div>
                <div><dt class="text-xs text-muted">{{ __('leads.received') }}</dt><dd>{{ $lead->created_at->timezone($tz)->format('d.m.Y H:i') }}</dd></div>
            </dl>
            @if ($lead->message)
                <div class="mt-4 border-t border-line pt-4">
                    <p class="text-xs text-muted">{{ __('leads.message') }}</p>
                    <p class="mt-1 whitespace-pre-line text-sm" lang="{{ $lead->locale }}">{{ $lead->message }}</p>
                </div>
            @endif
        </section>

        @can('leads.manage')
            <form wire:submit="addNote" class="panel space-y-3 p-5">
                <label for="note" class="field-label">{{ __('leads.add_note') }}</label>
                <textarea id="note" wire:model="note" rows="3" class="input" aria-describedby="note-hint"></textarea>
                <p id="note-hint" class="field-hint">{{ __('leads.note_hint') }}</p>
                @error('note')<p class="field-error">{{ $message }}</p>@enderror
                <button type="submit" class="btn-primary">{{ __('leads.save_note') }}</button>
            </form>
        @endcan

        <section aria-labelledby="hist-title">
            <h2 id="hist-title" class="text-base font-semibold">{{ __('leads.history') }}</h2>
            <ol class="panel mt-3 divide-y divide-line" role="list">
                @forelse ($lead->events as $e)
                    <li class="px-4 py-3 text-sm">
                        <p class="text-xs text-muted">{{ $e->created_at->timezone($tz)->format('d.m.Y H:i') }} · {{ $e->user?->name ?? __('leads.website') }}</p>
                        <p class="mt-0.5">
                            @switch($e->type)
                                @case('status') {{ __('leads.events.status', ['from' => __('leads.statuses.'.$e->from_status), 'to' => __('leads.statuses.'.$e->to_status)]) }} @break
                                @case('note') <span class="whitespace-pre-line">{{ $e->note }}</span> @break
                                @case('assigned') {{ __('leads.events.assigned', ['name' => $e->note ?? '—']) }} @break
                                @case('converted') {{ __('leads.events.converted', ['code' => $e->note]) }} @break
                                @default {{ __('leads.events.created') }}
                            @endswitch
                        </p>
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-sm text-muted">{{ __('crm.empty_list') }}</li>
                @endforelse
            </ol>
        </section>

        @can('tasks.manage')
            <section aria-labelledby="tasks-title">
                <h2 id="tasks-title" class="mb-3 text-base font-semibold">{{ __('tasks.title') }}</h2>
                <livewire:crm.task-list subject-type="lead" :subject-id="$lead->id" :key="'lead-tasks-'.$lead->id" />
            </section>
        @endcan
    </div>

    <aside class="space-y-6">
        @can('leads.manage')
            <section class="panel space-y-4 p-4">
                <div>
                    <label for="lead-status" class="field-label">{{ __('crm.fields.status') }}</label>
                    <select id="lead-status" wire:model.live="status" class="input">
                        @foreach (\App\Models\Lead::STATUSES as $s)
                            <option value="{{ $s }}" @disabled($s === 'converted' && $lead->status !== 'converted')>{{ __('leads.statuses.'.$s) }}</option>
                        @endforeach
                    </select>
                </div>
                <form wire:submit="saveAssignment" class="space-y-3 border-t border-line pt-4">
                    <div>
                        <label for="lead-assignee" class="field-label">{{ __('leads.assignee') }}</label>
                        <select id="lead-assignee" wire:model="assignee" class="input">
                            <option value="">—</option>
                            @foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                        </select>
                    </div>
                    <x-admin.text model="dueAt" type="datetime-local" :label="__('leads.due_at')" />
                    <button type="submit" class="btn-secondary w-full">{{ __('crm.actions.save') }}</button>
                </form>
            </section>

            <section class="panel p-4" aria-labelledby="conv-title">
                <h2 id="conv-title" class="text-sm font-semibold">{{ __('leads.patient') }}</h2>
                @if ($lead->patient)
                    <p class="mt-2 text-sm"><a href="{{ route('crm.patients.show', $lead->patient) }}" wire:navigate class="btn-link">{{ $lead->patient->fullName() }}</a> <span class="text-muted">· {{ $lead->patient->code }}</span></p>
                @else
                    <p class="mt-1 text-xs text-muted">{{ __('leads.convert_hint') }}</p>
                    @can('patients.view')
                        <label for="pq" class="field-label mt-3">{{ __('leads.find_patient') }}</label>
                        <input id="pq" type="search" class="input" wire:model.live.debounce.300ms="patientQuery" autocomplete="off">
                        @if ($candidates->isNotEmpty())
                        <ul class="mt-2 divide-y divide-line rounded-lg ring-1 ring-line" role="list">
                            @foreach ($candidates as $c)
                                <li class="flex items-center gap-2 px-3 py-2 text-sm">
                                    <span class="min-w-0 flex-1">{{ $c->fullName() }} <span class="block text-xs text-muted">{{ $c->phone }} · {{ $c->birth_date?->format('d.m.Y') }}</span></span>
                                    <button type="button" class="btn-link shrink-0 text-xs" wire:click="convertTo({{ $c->id }})">{{ __('leads.link') }}</button>
                                </li>
                            @endforeach
                        </ul>
                        @endif
                    @endcan
                    @can('patients.create')
                        <button type="button" class="btn-secondary mt-3 w-full" wire:click="convertNew" wire:confirm="{{ __('leads.confirm_new_patient') }}">{{ __('leads.new_patient') }}</button>
                    @endcan
                @endif
            </section>
        @endcan
    </aside>
</div>
