<div class="max-w-4xl">
    <p class="max-w-prose text-sm text-muted">{{ __('cms.menus.intro') }}</p>
    <div class="mt-4 flex flex-wrap items-center gap-3">
        <div class="flex rounded-lg bg-white p-0.5 ring-1 ring-line" role="tablist" aria-label="{{ __('cms.menus.title') }}">
            @foreach (\App\Livewire\Cms\MenuEditor::MENUS as $m)
                <button type="button" role="tab" aria-selected="{{ $menu === $m ? 'true' : 'false' }}" wire:click="$set('menu', '{{ $m }}')"
                        @class(['min-h-10 rounded-md px-4 text-sm font-semibold', 'bg-ink text-white' => $menu === $m, 'text-ink hover:bg-mist' => $menu !== $m])>{{ __('cms.menus.menus.'.$m) }}</button>
            @endforeach
        </div>
        <button type="button" class="btn-primary ml-auto" wire:click="create">{{ __('cms.menus.add') }}</button>
    </div>

    @if ($editing !== null)
        <form wire:submit="save" class="panel mt-6 space-y-5 p-5">
            <h2 class="text-base font-semibold">{{ $editing ? __('cms.menus.edit') : __('cms.menus.add') }}</h2>
            <div class="grid gap-5 md:grid-cols-2">
                <x-admin.text model="form.label.ro" :label="__('cms.menus.label').' (RO)'" required maxlength="60" />
                <x-admin.text model="form.label.hu" :label="__('cms.menus.label').' (HU)'" maxlength="60" />
                <div>
                    <label for="kind" class="field-label">{{ __('cms.menus.kind') }}</label>
                    <select id="kind" class="input" wire:model.live="form.kind">
                        <option value="page">{{ __('cms.menus.kinds.page') }}</option>
                        <option value="booking">{{ __('cms.menus.kinds.booking') }}</option>
                        <option value="url">{{ __('cms.menus.kinds.url') }}</option>
                    </select>
                </div>
                @if (($form['kind'] ?? 'page') === 'page')
                    <div>
                        <label for="page" class="field-label">{{ __('cms.menus.page') }}</label>
                        <select id="page" class="input" wire:model="form.page_id" @error('form.page_id') aria-invalid="true" @enderror>
                            <option value="">—</option>
                            @foreach ($pages as $p)<option value="{{ $p->id }}">{{ $p->title() }}</option>@endforeach
                        </select>
                        @error('form.page_id')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                @elseif (($form['kind'] ?? '') === 'url')
                    <x-admin.text model="form.target" :label="__('cms.menus.url')" :hint="__('cms.menus.url_hint')" />
                @endif
            </div>
            <x-admin.toggle model="form.is_active" :label="__('cms.menus.active')" />
            <div class="flex gap-3">
                <button type="submit" class="btn-primary">{{ __('crm.actions.save') }}</button>
                <button type="button" class="btn-secondary" wire:click="$set('editing', null)">{{ __('crm.actions.cancel') }}</button>
            </div>
        </form>
    @endif

    <ol class="panel mt-6 divide-y divide-line" role="list">
        @forelse ($items as $item)
            <li class="flex flex-wrap items-center gap-3 px-4 py-3" wire:key="menu-{{ $item->id }}">
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-heading">{{ $item->label('ro') }} <span class="font-normal text-muted" lang="hu">/ {{ $item->label('hu') }}</span></p>
                    <p class="truncate text-xs text-muted">{{ $item->url('ro') }}</p>
                </div>
                @unless ($item->is_active)<span class="badge-neutral">{{ __('crm.state.hidden') }}</span>@endunless
                <div class="flex items-center gap-1">
                    <button type="button" class="btn-secondary min-h-10 px-2.5" wire:click="move({{ $item->id }}, -1)" @disabled($loop->first) aria-label="{{ __('cms.menus.move_up', ['label' => $item->label()]) }}">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 5l5 6H5z"/></svg>
                    </button>
                    <button type="button" class="btn-secondary min-h-10 px-2.5" wire:click="move({{ $item->id }}, 1)" @disabled($loop->last) aria-label="{{ __('cms.menus.move_down', ['label' => $item->label()]) }}">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 15l-5-6h10z"/></svg>
                    </button>
                    <button type="button" class="btn-link px-2 text-sm" wire:click="edit({{ $item->id }})">{{ __('crm.actions.edit') }}</button>
                    <button type="button" class="btn-link px-2 text-sm text-bad" wire:click="delete({{ $item->id }})" wire:confirm="{{ __('cms.menus.confirm_delete') }}">{{ __('cms.menus.delete') }}</button>
                </div>
            </li>
        @empty
            <li class="px-4 py-10 text-center text-sm text-muted">{{ __('crm.empty_list') }}</li>
        @endforelse
    </ol>
</div>
