<div class="max-w-6xl">
    <div class="flex flex-wrap items-end gap-4">
        <p class="max-w-prose text-sm text-muted">{{ __('cms.pages.intro') }}</p>
        <label class="flex min-h-11 items-center gap-2 text-sm">
            <input type="checkbox" wire:model.live="showDeleted" class="h-5 w-5 rounded border-line text-ink focus:ring-ink">
            {{ __('crm.show_deleted') }}
        </label>
        @can('seo.manage')
            <button type="button" class="btn-primary ml-auto" wire:click="$toggle('creating')" aria-expanded="{{ $creating ? 'true' : 'false' }}" aria-controls="new-page">{{ __('cms.pages.create') }}</button>
        @endcan
    </div>

    @if ($creating)
        <form id="new-page" wire:submit="create" class="panel mt-6 space-y-5 p-5">
            <h2 class="text-base font-semibold">{{ __('cms.pages.create') }}</h2>
            <div class="grid gap-5 md:grid-cols-2">
                <x-admin.text model="title_ro" :label="__('cms.pages.page_title').' (RO)'" required />
                <x-admin.text model="title_hu" :label="__('cms.pages.page_title').' (HU)'" />
                <x-admin.text model="slug_ro" :label="__('cms.pages.slug').' (RO)'" :hint="__('cms.pages.slug_hint', ['url' => url('/').'/…'])" required />
                <x-admin.text model="slug_hu" :label="__('cms.pages.slug').' (HU)'" :hint="__('cms.pages.slug_hint', ['url' => url('/hu').'/…'])" required />
                <div>
                    <label for="template" class="field-label">{{ __('cms.pages.template') }}</label>
                    <select id="template" wire:model="template" class="input">
                        <option value="standard">{{ __('cms.pages.templates.standard') }}</option>
                        <option value="legal">{{ __('cms.pages.templates.legal') }}</option>
                    </select>
                </div>
            </div>
            <div class="flex gap-3">
                <button type="submit" class="btn-primary">{{ __('cms.pages.create_and_edit') }}</button>
                <button type="button" class="btn-secondary" wire:click="$set('creating', false)">{{ __('crm.actions.cancel') }}</button>
            </div>
        </form>
    @endif

    <div class="panel mt-6 overflow-x-auto">
        <table class="table-base table-stack">
            <thead>
            <tr>
                <th scope="col">{{ __('cms.pages.page') }}</th>
                <th scope="col">{{ __('cms.pages.address') }}</th>
                <th scope="col">{{ __('crm.fields.status') }}</th>
                <th scope="col">{{ __('cms.pages.updated') }}</th>
                <th scope="col"><span class="sr-only">{{ __('crm.actions.actions') }}</span></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($pages as $p)
                <tr wire:key="page-{{ $p->id }}">
                    <td>
                        <p class="font-medium text-heading">{{ $p->title(published: false) }}</p>
                        <p class="text-xs text-muted">{{ __('cms.pages.templates.'.$p->template) }}@if ($p->is_system) · {{ __('cms.pages.system') }}@endif</p>
                    </td>
                    <td class="text-xs" data-label="{{ __('cms.pages.address') }}">
                        <span class="block">/{{ $p->slug_ro }}</span>
                        <span class="block text-muted">/hu/{{ $p->slug_hu }}</span>
                    </td>
                    <td data-label="{{ __('crm.fields.status') }}"><x-admin.publish-state :model="$p" /></td>
                    <td class="whitespace-nowrap text-xs text-muted" data-label="{{ __('cms.pages.updated') }}">{{ $p->updated_at?->timezone(config('optica.clinic_timezone'))->format('d.m.Y H:i') }}</td>
                    <td class="cell-actions text-right">
                        @if ($p->trashed())
                            <button type="button" wire:click="restore({{ $p->id }})" class="btn-link text-sm">{{ __('crm.actions.restore') }}</button>
                        @else
                            <a href="{{ route('cms.pages.edit', $p) }}" wire:navigate class="btn-link text-sm">{{ __('crm.actions.edit') }}</a>
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
