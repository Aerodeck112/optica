<div class="{{ $subjectType ? '' : 'max-w-5xl' }}">
    <div class="flex flex-wrap items-end gap-3">
        @unless ($subjectType)
            <div class="flex rounded-lg bg-white p-0.5 ring-1 ring-line" role="group" aria-label="{{ __('tasks.scope') }}">
                @foreach (['mine', 'team'] as $s)
                    <button type="button" wire:click="$set('scope', '{{ $s }}')" aria-pressed="{{ $scope === $s ? 'true' : 'false' }}"
                            @class(['min-h-10 rounded-md px-4 text-sm font-semibold', 'bg-ink text-white' => $scope === $s, 'text-ink hover:bg-mist' => $scope !== $s])>{{ __('tasks.scopes.'.$s) }}</button>
                @endforeach
            </div>
        @endunless
        <div>
            <label for="task-status" class="sr-only">{{ __('crm.fields.status') }}</label>
            <select id="task-status" wire:model.live="status" class="input">
                @foreach (['open', 'done', 'all'] as $s)<option value="{{ $s }}">{{ __('tasks.filters.'.$s) }}</option>@endforeach
            </select>
        </div>
        <button type="button" class="btn-primary ml-auto" wire:click="create">{{ __('tasks.create') }}</button>
    </div>

    @if ($creating)
        <form wire:submit="save" class="panel mt-4 space-y-4 p-5">
            <div class="grid gap-4 md:grid-cols-2">
                <x-admin.text model="form.title" :label="__('tasks.fields.title')" required maxlength="200" />
                <x-admin.text model="form.due_at" type="datetime-local" :label="__('tasks.fields.due_at')" />
                <div>
                    <label for="task-user" class="field-label">{{ __('tasks.fields.assigned_to') }}</label>
                    <select id="task-user" wire:model="form.assigned_to" class="input">
                        <option value="">—</option>
                        @foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                    </select>
                </div>
                <div class="flex items-end pb-2"><x-admin.toggle model="form.is_urgent" :label="__('tasks.fields.is_urgent')" /></div>
            </div>
            <div>
                <label for="task-desc" class="field-label">{{ __('tasks.fields.description') }}</label>
                <textarea id="task-desc" wire:model="form.description" rows="2" class="input" aria-describedby="task-desc-hint"></textarea>
                <p id="task-desc-hint" class="field-hint">{{ __('tasks.no_clinical') }}</p>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="btn-primary">{{ __('crm.actions.save') }}</button>
                <button type="button" class="btn-secondary" wire:click="$set('creating', false)">{{ __('crm.actions.cancel') }}</button>
            </div>
        </form>
    @endif

    <ul class="panel mt-4 divide-y divide-line" role="list">
        @forelse ($tasks as $t)
            <li class="flex flex-wrap items-start gap-3 px-4 py-3" wire:key="t-{{ $t->id }}">
                @if ($t->status === 'open')
                    <button type="button" class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full ring-2 ring-line hover:ring-ink" wire:click="setStatus({{ $t->id }}, 'done')"
                            aria-label="{{ __('tasks.mark_done', ['title' => $t->title]) }}"></button>
                @else
                    <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-ok text-white" aria-hidden="true">✓</span>
                @endif
                <div class="min-w-0 flex-1">
                    <p @class(['font-medium', 'text-muted line-through' => $t->status !== 'open', 'text-heading' => $t->status === 'open'])>{{ $t->title }}
                        @if ($t->is_urgent)<span class="badge-bad ml-1">{{ __('tasks.urgent') }}</span>@endif</p>
                    @if ($t->description)<p class="mt-0.5 whitespace-pre-line text-sm text-body">{{ $t->description }}</p>@endif
                    <p class="mt-1 text-xs text-muted">
                        @if ($t->due_at)<span @class(['font-semibold text-bad' => $t->isOverdue()])>{{ __('tasks.due', ['date' => $t->due_at->timezone($tz)->format('d.m.Y H:i')]) }}</span> · @endif
                        {{ $t->assignee?->name ?? __('tasks.unassigned') }}
                        @if (! $subjectType && $t->subject)
                            · @if ($t->subject_type === 'patient')<a href="{{ route('crm.patients.show', $t->subject_id) }}" wire:navigate class="btn-link">{{ $t->subject->fullName() }}</a>
                            @elseif ($t->subject_type === 'lead')<a href="{{ route('crm.leads.show', $t->subject_id) }}" wire:navigate class="btn-link">{{ $t->subject->name }}</a>@endif
                        @endif
                    </p>
                </div>
                @if ($t->status !== 'open')
                    <button type="button" class="btn-link text-xs" wire:click="setStatus({{ $t->id }}, 'open')">{{ __('tasks.reopen') }}</button>
                @else
                    <button type="button" class="btn-link text-xs text-muted" wire:click="setStatus({{ $t->id }}, 'cancelled')" wire:confirm="{{ __('tasks.confirm_cancel') }}">{{ __('tasks.cancel') }}</button>
                @endif
            </li>
        @empty
            <li class="px-4 py-8 text-center text-sm text-muted">{{ __('tasks.none') }}</li>
        @endforelse
    </ul>
</div>
