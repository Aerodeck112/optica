@php($tz = config('optica.clinic_timezone'))
<div class="max-w-6xl">
    <div class="flex flex-wrap items-end gap-4">
        <div class="flex rounded-lg bg-white p-0.5 ring-1 ring-line" role="tablist">
            <button type="button" role="tab" aria-selected="{{ $tab === 'redirects' ? 'true' : 'false' }}" wire:click="$set('tab', 'redirects')"
                    @class(['min-h-10 rounded-md px-4 text-sm font-semibold', 'bg-ink text-white' => $tab === 'redirects', 'text-ink hover:bg-mist' => $tab !== 'redirects'])>{{ __('cms.redirects.tab_redirects') }}</button>
            <button type="button" role="tab" aria-selected="{{ $tab === 'not_found' ? 'true' : 'false' }}" wire:click="$set('tab', 'not_found')"
                    @class(['min-h-10 rounded-md px-4 text-sm font-semibold', 'bg-ink text-white' => $tab === 'not_found', 'text-ink hover:bg-mist' => $tab !== 'not_found'])>{{ __('cms.redirects.tab_not_found', ['count' => $notFoundCount]) }}</button>
        </div>
        <div class="w-full max-w-xs">
            <label for="search" class="field-label">{{ __('crm.actions.search') }}</label>
            <input id="search" type="search" wire:model.live.debounce.300ms="search" class="input">
        </div>
        @if ($tab === 'redirects')
            <button type="button" class="btn-primary ml-auto" wire:click="create">{{ __('cms.redirects.add') }}</button>
        @endif
    </div>

    @if ($editing !== null)
        <form wire:submit="save" class="panel mt-6 space-y-5 p-5">
            <div class="grid gap-5 md:grid-cols-[1fr_1fr_12rem]">
                <x-admin.text model="from_path" :label="__('cms.redirects.from')" placeholder="/adresa-veche" required />
                <x-admin.text model="to_url" :label="__('cms.redirects.to')" placeholder="/adresa-noua" :hint="__('cms.redirects.to_hint')" />
                <div>
                    <label for="status_code" class="field-label">{{ __('cms.redirects.type') }}</label>
                    <select id="status_code" wire:model.live="status_code" class="input">
                        @foreach ([301, 302, 410] as $code)<option value="{{ $code }}">{{ __('cms.redirects.codes.'.$code) }}</option>@endforeach
                    </select>
                </div>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="btn-primary">{{ __('crm.actions.save') }}</button>
                <button type="button" class="btn-secondary" wire:click="$set('editing', null)">{{ __('crm.actions.cancel') }}</button>
            </div>
        </form>
    @endif

    <div class="panel mt-6 overflow-x-auto">
        @if ($tab === 'redirects')
            <table class="table-base table-stack">
                <thead><tr>
                    <th scope="col">{{ __('cms.redirects.from') }}</th>
                    <th scope="col">{{ __('cms.redirects.to') }}</th>
                    <th scope="col">{{ __('cms.redirects.type') }}</th>
                    <th scope="col">{{ __('cms.redirects.hits') }}</th>
                    <th scope="col"><span class="sr-only">{{ __('crm.actions.actions') }}</span></th>
                </tr></thead>
                <tbody>
                @forelse ($rows as $r)
                    <tr wire:key="r-{{ $r->id }}">
                        <td class="break-all font-medium">{{ $r->from_path }}</td>
                        <td class="break-all" data-label="{{ __('cms.redirects.to') }}">{{ $r->to_url ?? '—' }}</td>
                        <td data-label="{{ __('cms.redirects.type') }}"><span class="{{ (int) $r->status_code === 410 ? 'badge-neutral' : 'badge-info' }}">{{ $r->status_code }}</span></td>
                        <td class="whitespace-nowrap" data-label="{{ __('cms.redirects.hits') }}">{{ $r->hits }}@if ($r->last_hit_at)<span class="block text-xs text-muted">{{ $r->last_hit_at->timezone($tz)->format('d.m.Y') }}</span>@endif</td>
                        <td class="cell-actions whitespace-nowrap text-right">
                            <button type="button" class="btn-link text-sm" wire:click="edit({{ $r->id }})">{{ __('crm.actions.edit') }}</button>
                            <button type="button" class="btn-link ml-3 text-sm text-bad" wire:click="delete({{ $r->id }})" wire:confirm="{{ __('cms.redirects.confirm_delete') }}">{{ __('cms.redirects.delete') }}</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-10 text-center text-muted">{{ __('crm.empty_list') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        @else
            <table class="table-base table-stack">
                <thead><tr>
                    <th scope="col">{{ __('cms.redirects.missing_path') }}</th>
                    <th scope="col">{{ __('cms.redirects.hits') }}</th>
                    <th scope="col">{{ __('cms.redirects.last_seen') }}</th>
                    <th scope="col"><span class="sr-only">{{ __('crm.actions.actions') }}</span></th>
                </tr></thead>
                <tbody>
                @forelse ($rows as $r)
                    <tr wire:key="nf-{{ $r->id }}">
                        <td class="break-all font-medium">{{ $r->path }}@if ($r->last_referer)<span class="block text-xs font-normal text-muted">{{ __('cms.redirects.from_page', ['url' => $r->last_referer]) }}</span>@endif</td>
                        <td data-label="{{ __('cms.redirects.hits') }}">{{ $r->hits }}</td>
                        <td class="whitespace-nowrap" data-label="{{ __('cms.redirects.last_seen') }}">{{ $r->last_seen_at->timezone($tz)->format('d.m.Y H:i') }}</td>
                        <td class="cell-actions whitespace-nowrap text-right">
                            <button type="button" class="btn-link text-sm" wire:click="create(@js($r->path))">{{ __('cms.redirects.make_redirect') }}</button>
                            <button type="button" class="btn-link ml-3 text-sm text-muted" wire:click="dismiss({{ $r->id }})">{{ __('cms.redirects.dismiss') }}</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-10 text-center text-muted">{{ __('cms.redirects.no_404') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-6">{{ $rows->links() }}</div>
</div>
