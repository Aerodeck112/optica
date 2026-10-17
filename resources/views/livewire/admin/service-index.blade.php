<div class="max-w-6xl">
    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full max-w-xs">
            <label for="search" class="field-label">{{ __('crm.actions.search') }}</label>
            <input id="search" type="search" wire:model.live.debounce.300ms="search" class="input" placeholder="{{ __('crm.services.search_placeholder') }}">
        </div>
        <label class="flex min-h-11 items-center gap-2 text-sm">
            <input type="checkbox" wire:model.live="showDeleted" class="h-5 w-5 rounded border-line text-ink focus:ring-ink">
            {{ __('crm.show_deleted') }}
        </label>
        <a href="{{ route('admin.services.create') }}" wire:navigate class="btn-primary ml-auto">{{ __('crm.services.create') }}</a>
    </div>

    <div class="panel mt-6 overflow-x-auto">
        <table class="table-base table-stack">
            <thead>
            <tr>
                <th scope="col">{{ __('crm.fields.service') }}</th>
                <th scope="col">{{ __('crm.fields.default_duration') }}</th>
                <th scope="col">{{ __('crm.fields.default_price') }}</th>
                <th scope="col">{{ __('crm.fields.visibility') }}</th>
                <th scope="col"><span class="sr-only">{{ __('crm.actions.actions') }}</span></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($services as $s)
                <tr wire:key="svc-{{ $s->id }}">
                    <td>
                        <div class="flex items-start gap-3">
                            <span class="mt-1 h-3 w-3 shrink-0 rounded-full ring-1 ring-black/10" style="background: {{ $s->color ?? '#c8dbe9' }}" aria-hidden="true"></span>
                            <div>
                                <p class="font-medium text-heading">{{ $s->name() }}</p>
                                <p class="text-xs text-muted">{{ __('crm.services.categories.'.($s->category ?? 'other')) }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="whitespace-nowrap" data-label="{{ __('crm.fields.default_duration') }}">{{ __('crm.minutes', ['count' => $s->default_duration]) }}</td>
                    <td class="whitespace-nowrap" data-label="{{ __('crm.fields.default_price') }}">{{ $s->default_price !== null ? number_format((float) $s->default_price, 2, ',', '.').' lei' : '—' }}</td>
                    <td data-label="{{ __('crm.fields.visibility') }}">
                        @if (! $s->is_active)
                            <span class="badge-bad">{{ __('crm.state.inactive') }}</span>
                        @elseif ($s->is_public)
                            <x-admin.publish-state :model="$s" />
                        @else
                            <span class="badge-neutral">{{ __('crm.state.hidden') }}</span>
                        @endif
                    </td>
                    <td class="cell-actions text-right">
                        @if ($s->trashed())
                            <button type="button" wire:click="restore({{ $s->id }})" class="btn-link text-sm">{{ __('crm.actions.restore') }}</button>
                        @else
                            <a href="{{ route('admin.services.edit', $s) }}" wire:navigate class="btn-link text-sm">{{ __('crm.actions.edit') }}</a>
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
