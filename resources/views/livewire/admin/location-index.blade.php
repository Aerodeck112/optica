<div class="max-w-6xl">
    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full max-w-xs">
            <label for="search" class="field-label">{{ __('crm.actions.search') }}</label>
            <input id="search" type="search" wire:model.live.debounce.300ms="search" class="input" placeholder="{{ __('crm.locations.search_placeholder') }}">
        </div>
        <label class="flex min-h-11 items-center gap-2 text-sm">
            <input type="checkbox" wire:model.live="showDeleted" class="h-5 w-5 rounded border-line text-ink focus:ring-ink">
            {{ __('crm.show_deleted') }}
        </label>
        <a href="{{ route('admin.locations.create') }}" wire:navigate class="btn-primary ml-auto">{{ __('crm.locations.create') }}</a>
    </div>

    <div class="panel mt-6 overflow-x-auto">
        <table class="table-base table-stack">
            <thead>
            <tr>
                <th scope="col">{{ __('crm.fields.location') }}</th>
                <th scope="col">{{ __('crm.fields.phone') }}</th>
                <th scope="col">{{ __('crm.fields.visibility') }}</th>
                <th scope="col">{{ __('crm.fields.online_booking') }}</th>
                <th scope="col"><span class="sr-only">{{ __('crm.actions.actions') }}</span></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($locations as $location)
                <tr wire:key="loc-{{ $location->id }}">
                    <td>
                        <p class="font-medium text-heading">{{ $location->name() }}</p>
                        <p class="text-xs text-muted">{{ $location->code }}</p>
                    </td>
                    <td class="whitespace-nowrap" data-label="{{ __('crm.fields.phone') }}">{{ $location->phone }}</td>
                    <td data-label="{{ __('crm.fields.visibility') }}">
                        @if (! $location->is_active)
                            <span class="badge-bad">{{ __('crm.state.inactive') }}</span>
                        @elseif ($location->is_public)
                            <x-admin.publish-state :model="$location" />
                        @else
                            <span class="badge-neutral">{{ __('crm.state.hidden') }}</span>
                        @endif
                    </td>
                    <td data-label="{{ __('crm.fields.online_booking') }}">
                        @if ($location->online_booking_enabled)
                            <span class="badge-ok">{{ __('crm.state.on') }}</span>
                        @else
                            <span class="badge-neutral">{{ __('crm.state.off') }}</span>
                        @endif
                    </td>
                    <td class="cell-actions text-right">
                        @if ($location->trashed())
                            <button type="button" wire:click="restore({{ $location->id }})" class="btn-link text-sm">{{ __('crm.actions.restore') }}</button>
                        @else
                            <a href="{{ route('admin.locations.edit', $location) }}" wire:navigate class="btn-link text-sm">{{ __('crm.actions.edit') }}</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-10 text-center text-muted">{{ __('crm.empty_list') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
