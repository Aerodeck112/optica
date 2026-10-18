<div class="max-w-7xl">
    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full max-w-xs">
            <label for="search" class="field-label">{{ __('crm.actions.search') }}</label>
            <input id="search" type="search" wire:model.live.debounce.300ms="search" class="input">
        </div>
        <label class="flex min-h-11 items-center gap-2 text-sm">
            <input type="checkbox" wire:model.live="missingAlt" class="h-5 w-5 rounded border-line text-ink focus:ring-ink">
            {{ __('cms.media.only_missing_alt') }}
        </label>
        <div class="ml-auto">
            <label for="uploads" class="btn-primary cursor-pointer">{{ __('cms.media.upload') }}</label>
            <input id="uploads" type="file" multiple accept="image/jpeg,image/png,image/webp" wire:model="uploads" class="sr-only">
        </div>
    </div>
    <p class="mt-2 text-xs text-muted">{{ __('cms.media.upload_hint') }}</p>
    <div aria-live="polite">
        <p wire:loading wire:target="uploads" class="mt-3 text-sm text-muted">{{ __('crm.uploading') }}</p>
        @error('uploads.*')<p class="field-error">{{ $message }}</p>@enderror
        @if ($notice)<div class="mt-4 rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ $notice }}</div>@endif
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5" role="list">
            @forelse ($items as $m)
                <li wire:key="media-{{ $m->id }}">
                    <button type="button" wire:click="select({{ $m->id }})"
                            @class(['block w-full overflow-hidden rounded-lg bg-white text-left ring-1 ring-line hover:ring-2 hover:ring-ink focus-visible:ring-2 focus-visible:ring-ink', 'ring-2 ring-ink' => $selected === $m->id])
                            aria-pressed="{{ $selected === $m->id ? 'true' : 'false' }}">
                        <img src="{{ $m->url(480) }}" alt="" class="aspect-[4/3] w-full object-cover" loading="lazy">
                        <span class="block truncate px-2 pt-1.5 text-xs font-medium">{{ $m->altText('ro') ?: ($m->original_name ?? '#'.$m->id) }}</span>
                        <span class="block px-2 pb-1.5 text-xs text-muted">{{ $m->width }}×{{ $m->height }} · {{ number_format($m->size / 1024, 0) }} KB
                            @if (($m->alt['ro'] ?? '') === '' || ($m->alt['hu'] ?? '') === '')<span class="text-warn">· {{ __('cms.media.no_alt_short') }}</span>@endif
                        </span>
                    </button>
                </li>
            @empty
                <li class="col-span-full py-10 text-center text-sm text-muted">{{ __('cms.media.empty') }}</li>
            @endforelse
        </ul>

        <aside>
            @if ($current)
                <form wire:submit="saveAlt" class="panel space-y-4 p-4 lg:sticky lg:top-20">
                    <img src="{{ $current->url(960) }}" alt="" class="w-full rounded-lg">
                    <p class="break-all text-xs text-muted">{{ $current->original_name }} · {{ $current->width }}×{{ $current->height }}</p>
                    <x-admin.text model="alt.ro" :label="__('cms.media.alt').' (RO)'" maxlength="200" />
                    <x-admin.text model="alt.hu" :label="__('cms.media.alt').' (HU)'" maxlength="200" :hint="__('cms.media.alt_hint')" />
                    <button type="submit" class="btn-primary w-full">{{ __('crm.actions.save') }}</button>
                    <div class="border-t border-line pt-3 text-sm">
                        <p class="font-medium">{{ __('cms.media.used_in') }}</p>
                        @if ($usages)
                            <ul class="mt-1 list-disc pl-5 text-xs text-muted">
                                @foreach ($usages as $u)<li>{{ str_starts_with($u, 'page:') ? __('cms.media.usage_page', ['key' => substr($u, 5)]) : __('cms.media.usage_'.$u) }}</li>@endforeach
                            </ul>
                        @else
                            <p class="text-xs text-muted">{{ __('cms.media.unused') }}</p>
                        @endif
                    </div>
                    @error('delete')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    <button type="button" class="btn-danger w-full" wire:click="delete" wire:confirm="{{ __('cms.media.confirm_delete') }}" @disabled($usages)>{{ __('cms.media.delete') }}</button>
                </form>
            @else
                <p class="panel p-6 text-sm text-muted">{{ __('cms.media.select_hint') }}</p>
            @endif
        </aside>
    </div>
    <div class="mt-6">{{ $items->links() }}</div>
</div>
