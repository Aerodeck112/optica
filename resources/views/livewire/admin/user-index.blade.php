<div class="max-w-6xl">
    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full max-w-xs">
            <label for="search" class="field-label">{{ __('crm.actions.search') }}</label>
            <input id="search" type="search" wire:model.live.debounce.300ms="search" class="input" placeholder="{{ __('users.search_placeholder') }}">
        </div>
        <div>
            <label for="role" class="field-label">{{ __('users.fields.role') }}</label>
            <select id="role" wire:model.live="role" class="input">
                <option value="">{{ __('crm.all') }}</option>
                @foreach ($roles as $r)
                    <option value="{{ $r->key }}">{{ $r->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="location" class="field-label">{{ __('crm.fields.location') }}</label>
            <select id="location" wire:model.live="location" class="input">
                <option value="">{{ __('crm.all') }}</option>
                @foreach ($locations as $l)
                    <option value="{{ $l->id }}">{{ $l->name() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="state" class="field-label">{{ __('crm.fields.status') }}</label>
            <select id="state" wire:model.live="state" class="input">
                <option value="active">{{ __('users.state.active') }}</option>
                <option value="inactive">{{ __('users.state.inactive') }}</option>
                <option value="all">{{ __('crm.all') }}</option>
            </select>
        </div>
        @can('users.manage')
            <a href="{{ route('admin.users.create') }}" wire:navigate class="btn-primary ml-auto">{{ __('users.create') }}</a>
        @endcan
    </div>

    <div class="panel mt-6 overflow-x-auto">
        <table class="table-base table-stack">
            <thead>
            <tr>
                <th scope="col">{{ __('crm.fields.name') }}</th>
                <th scope="col">{{ __('users.fields.roles') }}</th>
                <th scope="col">{{ __('crm.fields.locations') }}</th>
                <th scope="col">{{ __('users.fields.security') }}</th>
                <th scope="col"><span class="sr-only">{{ __('crm.actions.actions') }}</span></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($users as $u)
                <tr wire:key="u-{{ $u->id }}">
                    <td>
                        <p class="font-medium text-heading">{{ $u->name }}</p>
                        <p class="text-xs text-muted">{{ $u->email }}</p>
                        @if ($u->specialist)<p class="text-xs text-ink">{{ __('users.linked_to', ['name' => $u->specialist->display_name]) }}</p>@endif
                    </td>
                    <td data-label="{{ __('users.fields.roles') }}">{{ $u->roles->map->label()->join(', ') ?: '—' }}</td>
                    <td data-label="{{ __('crm.fields.locations') }}" class="text-sm">
                        {{ $u->all_locations ? __('users.all_locations') : ($u->locations->map->name()->join(', ') ?: '—') }}
                    </td>
                    <td data-label="{{ __('users.fields.security') }}">
                        <div class="flex flex-wrap gap-1.5">
                            @if ($u->trashed() || ! $u->is_active)
                                <span class="badge-bad">{{ __('users.state.inactive') }}</span>
                            @endif
                            @if ($u->two_factor_confirmed_at)
                                <span class="badge-ok">{{ __('users.two_factor_on') }}</span>
                            @else
                                <span class="badge-neutral">{{ __('users.two_factor_off') }}</span>
                            @endif
                        </div>
                    </td>
                    <td class="cell-actions text-right">
                        @can('users.manage')
                            <a href="{{ route('admin.users.edit', $u) }}" wire:navigate class="btn-link text-sm">{{ __('crm.actions.edit') }}</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-10 text-center text-muted">{{ __('crm.empty_list') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $users->links() }}</div>
</div>
