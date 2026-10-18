<div class="max-w-6xl">
    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full max-w-md">
            <label for="search" class="field-label">{{ __('crm.actions.search') }}</label>
            <input id="search" type="search" wire:model.live.debounce.300ms="search" class="input" placeholder="{{ __('patients.search_placeholder') }}" autocomplete="off">
        </div>
        <label class="flex min-h-11 items-center gap-2 text-sm">
            <input type="checkbox" wire:model.live="archived" class="h-5 w-5 rounded border-line text-ink focus:ring-ink">
            {{ __('patients.show_archived') }}
        </label>
        @can('patients.create')
            <button type="button" class="btn-primary ml-auto" wire:click="startCreate">{{ __('patients.create') }}</button>
        @endcan
    </div>

    @if ($creating)
        <form wire:submit="create" class="panel mt-6 space-y-5 p-5" data-preserve>
            <h2 class="text-base font-semibold">{{ __('patients.create') }}</h2>
            <div class="grid gap-5 md:grid-cols-3">
                <x-admin.text model="form.last_name" :label="__('patients.fields.last_name')" required autocomplete="off" />
                <x-admin.text model="form.first_name" :label="__('patients.fields.first_name')" required autocomplete="off" />
                <x-admin.text model="form.birth_date" type="date" :label="__('patients.fields.birth_date')" />
                <x-admin.text model="form.phone" type="tel" :label="__('crm.fields.phone')" :hint="__('patients.phone_hint')" />
                <x-admin.text model="form.email" type="email" :label="__('crm.fields.email')" />
                <div>
                    <label for="locale" class="field-label">{{ __('patients.fields.locale') }}</label>
                    <select id="locale" wire:model="form.locale" class="input">
                        @foreach (config('optica.locales') as $l)<option value="{{ $l }}">{{ __('crm.languages.'.$l) }}</option>@endforeach
                    </select>
                </div>
            </div>
            @if ($duplicates->isNotEmpty())
                <div class="rounded-lg bg-warn-bg p-4 text-sm text-warn" role="alert">
                    <p class="font-semibold">{{ __('patients.duplicates_title') }}</p>
                    <p class="mt-1">{{ __('patients.duplicates_hint') }}</p>
                    <ul class="mt-3 space-y-1">
                        @foreach ($duplicates as $d)
                            <li><a href="{{ route('crm.patients.show', $d) }}" wire:navigate class="font-semibold text-ink underline">{{ $d->fullName() }}</a>
                                <span class="text-body">· {{ $d->code }} · {{ $d->birth_date?->format('d.m.Y') ?? '—' }} · {{ $d->phone ?? '—' }}</span></li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div class="flex flex-wrap gap-3">
                <button type="submit" class="btn-primary">{{ $duplicates->isNotEmpty() ? __('patients.create_anyway') : __('patients.create') }}</button>
                <button type="button" class="btn-secondary" wire:click="$set('creating', false)">{{ __('crm.actions.cancel') }}</button>
            </div>
        </form>
    @endif

    <div class="panel mt-6 overflow-x-auto">
        <table class="table-base table-stack">
            <thead>
            <tr>
                <th scope="col">{{ __('crm.fields.patient') }}</th>
                <th scope="col">{{ __('patients.fields.birth_date') }}</th>
                <th scope="col">{{ __('crm.fields.phone') }}</th>
                <th scope="col">{{ __('patients.fields.code') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse (($patients ?? $results) as $p)
                <tr wire:key="p-{{ $p->id }}">
                    <td><a href="{{ route('crm.patients.show', $p) }}" wire:navigate class="font-medium text-heading hover:underline">{{ $p->fullName() }}</a>
                        @if ($p->archived_at)<span class="badge-neutral ml-2">{{ __('patients.archived') }}</span>@endif</td>
                    <td data-label="{{ __('patients.fields.birth_date') }}">{{ $p->birth_date?->format('d.m.Y') ?? '—' }}</td>
                    <td data-label="{{ __('crm.fields.phone') }}">{{ $p->phone ?? '—' }}</td>
                    <td class="text-xs text-muted" data-label="{{ __('patients.fields.code') }}">{{ $p->code }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-10 text-center text-muted">{{ __('crm.empty_list') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($patients)<div class="mt-6">{{ $patients->links() }}</div>@endif
</div>
