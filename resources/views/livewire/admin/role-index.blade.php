<div class="max-w-4xl">
    <div class="flex flex-wrap items-end gap-4">
        <p class="max-w-prose text-sm text-muted">{{ __('users.roles.intro') }}</p>
        <a href="{{ route('admin.roles.create') }}" wire:navigate class="btn-primary ml-auto">{{ __('users.roles.create') }}</a>
    </div>
    <div class="panel mt-6 overflow-x-auto">
        <table class="table-base table-stack">
            <thead>
            <tr>
                <th scope="col">{{ __('users.fields.role') }}</th>
                <th scope="col">{{ __('users.roles.permissions_count') }}</th>
                <th scope="col">{{ __('users.roles.users_count') }}</th>
                <th scope="col"><span class="sr-only">{{ __('crm.actions.actions') }}</span></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($roles as $r)
                <tr wire:key="r-{{ $r->id }}">
                    <td>
                        <p class="font-medium text-heading">{{ $r->label() }}</p>
                        @if (! $r->is_system)<p class="text-xs text-muted">{{ __('users.roles.custom') }}</p>@endif
                    </td>
                    <td data-label="{{ __('users.roles.permissions_count') }}">{{ $r->permissions_count }}</td>
                    <td data-label="{{ __('users.roles.users_count') }}">{{ $r->users_count }}</td>
                    <td class="cell-actions text-right"><a href="{{ route('admin.roles.edit', $r) }}" wire:navigate class="btn-link text-sm">{{ __('crm.actions.edit') }}</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
