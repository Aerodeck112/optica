<section aria-labelledby="sec-resources">
    <h2 id="sec-resources" class="text-base font-semibold">{{ __('catalog.resources.title') }}</h2>
    <p class="mt-1 max-w-prose text-sm text-muted">{{ __('catalog.resources.hint') }}</p>
    @error('delete')<p class="field-error" role="alert">{{ $message }}</p>@enderror

    @if ($resources->isNotEmpty())
        <ul class="panel mt-4 divide-y divide-line">
            @foreach ($resources as $r)
                <li class="flex flex-wrap items-center gap-3 px-4 py-3" wire:key="res-{{ $r->id }}">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-heading">{{ $r->name[app()->getLocale()] ?? $r->name['ro'] }}</p>
                        <p class="text-xs text-muted">{{ __('catalog.resources.types.'.$r->type) }}</p>
                    </div>
                    @unless ($r->is_active)<span class="badge-bad">{{ __('crm.state.inactive') }}</span>@endunless
                    <button type="button" class="btn-link text-sm" wire:click="edit({{ $r->id }})">{{ __('crm.actions.edit') }}</button>
                    <button type="button" class="btn-link text-sm text-bad" wire:click="delete({{ $r->id }})" wire:confirm="{{ __('crm.actions.confirm_delete') }}">{{ __('catalog.resources.delete') }}</button>
                </li>
            @endforeach
        </ul>
    @endif

    <form wire:submit="save" class="panel mt-4 grid gap-4 p-4 md:grid-cols-[12rem_1fr_1fr_auto] md:items-end">
        <div>
            <label for="res-type" class="field-label">{{ __('crm.fields.type') }}</label>
            <select id="res-type" wire:model="type" class="input">
                @foreach (\App\Livewire\Admin\ResourceManager::TYPES as $t)
                    <option value="{{ $t }}">{{ __('catalog.resources.types.'.$t) }}</option>
                @endforeach
            </select>
        </div>
        @foreach (config('optica.locales') as $l)
            <div>
                <label for="res-name-{{ $l }}" class="field-label">{{ __('crm.fields.name') }} ({{ __('crm.languages.'.$l) }})</label>
                <input id="res-name-{{ $l }}" type="text" wire:model="name.{{ $l }}" lang="{{ $l }}" class="input" @error('name.'.$l) aria-invalid="true" @enderror>
                @error('name.'.$l)<p class="field-error">{{ $message }}</p>@enderror
            </div>
        @endforeach
        <div class="flex gap-2">
            <button type="submit" class="btn-secondary">{{ $editingId ? __('crm.actions.save') : __('catalog.resources.add') }}</button>
            @if ($editingId)<button type="button" class="btn-link text-sm" wire:click="cancel">{{ __('crm.actions.cancel') }}</button>@endif
        </div>
        @if ($editingId)
            <div class="md:col-span-4"><x-admin.toggle model="is_active" :label="__('crm.fields.is_active')" /></div>
        @endif
    </form>
</section>
