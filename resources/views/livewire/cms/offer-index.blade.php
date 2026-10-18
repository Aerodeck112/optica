<div class="max-w-6xl">
    <div class="flex flex-wrap items-end gap-4">
        <p class="max-w-prose text-sm text-muted">{{ __('cms.offers.intro') }}</p>
        <label class="flex min-h-11 items-center gap-2 text-sm">
            <input type="checkbox" wire:model.live="showDeleted" class="h-5 w-5 rounded border-line text-ink focus:ring-ink">
            {{ __('crm.show_deleted') }}
        </label>
        <a href="{{ route('cms.offers.create') }}" wire:navigate class="btn-primary ml-auto">{{ __('cms.offers.create') }}</a>
    </div>
    <div class="panel mt-6 overflow-x-auto">
        <table class="table-base table-stack">
            <thead>
            <tr>
                <th scope="col">{{ __('cms.offers.offer') }}</th>
                <th scope="col">{{ __('cms.offers.period') }}</th>
                <th scope="col">{{ __('cms.offers.on_site') }}</th>
                <th scope="col"><span class="sr-only">{{ __('crm.actions.actions') }}</span></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($offers as $o)
                @php($ended = $o->ends_on && $o->ends_on->toDateString() < $today)
                @php($upcoming = $o->starts_on && $o->starts_on->toDateString() > $today)
                <tr wire:key="offer-{{ $o->id }}">
                    <td>
                        <p class="font-medium text-heading">{{ $o->text('title', null, false) }}</p>
                        <p class="text-xs text-muted">/reduceri-actuale/{{ $o->slug_ro }}</p>
                    </td>
                    <td class="whitespace-nowrap" data-label="{{ __('cms.offers.period') }}">
                        {{ $o->starts_on?->format('d.m.Y') ?? '…' }} – {{ $o->ends_on?->format('d.m.Y') ?? '…' }}
                    </td>
                    <td data-label="{{ __('cms.offers.on_site') }}">
                        @if (! $o->published_at)<span class="badge-neutral">{{ __('crm.state.never_published') }}</span>
                        @elseif ($ended)<span class="badge-neutral">{{ __('cms.offers.ended') }}</span>
                        @elseif ($upcoming)<span class="badge-info">{{ __('cms.offers.upcoming') }}</span>
                        @else<span class="badge-ok">{{ __('cms.offers.visible') }}</span>@endif
                        @if ($o->published_at && $o->draft != $o->published)<span class="badge-warn">{{ __('crm.state.unpublished_changes') }}</span>@endif
                    </td>
                    <td class="cell-actions text-right">
                        @if ($o->trashed())
                            @can('cms.publish')<button type="button" wire:click="restore({{ $o->id }})" class="btn-link text-sm">{{ __('crm.actions.restore') }}</button>@endcan
                        @else
                            <a href="{{ route('cms.offers.edit', $o) }}" wire:navigate class="btn-link text-sm">{{ __('crm.actions.edit') }}</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-10 text-center text-muted">{{ __('crm.empty_list') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
