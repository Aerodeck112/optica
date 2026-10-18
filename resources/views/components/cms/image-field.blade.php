@props(['target', 'label', 'ids' => [], 'media', 'multiple' => false, 'hint' => null])
{{-- Image chosen from the media library; alt text comes from the library (CMS-03, a11y). --}}
<div>
    <p class="field-label">{{ $label }}</p>
    <ul class="flex flex-wrap gap-3" role="list">
        @foreach (array_filter((array) $ids) as $id)
            @php($m = $media[$id] ?? null)
            <li class="relative w-28" wire:key="img-{{ $target }}-{{ $id }}">
                @if ($m)
                    <img src="{{ $m->url(480) }}" alt="{{ $m->altText() }}" class="aspect-[4/3] w-28 rounded-lg object-cover ring-1 ring-line" loading="lazy">
                    @if ($m->altText() === '')<p class="mt-1 text-xs text-warn">{{ __('cms.media.missing_alt') }}</p>@endif
                @endif
                <button type="button" class="btn-link mt-1 text-xs text-bad" wire:click="removeImage('{{ $target }}', {{ (int) $id }})">{{ __('cms.media.remove') }}</button>
            </li>
        @endforeach
        @if ($multiple || ! array_filter((array) $ids))
            <li>
                <button type="button" class="flex aspect-[4/3] w-28 items-center justify-center rounded-lg border-2 border-dashed border-line text-sm font-medium text-ink hover:bg-mist"
                        wire:click="openPicker('{{ $target }}')">{{ __('cms.media.choose') }}</button>
            </li>
        @endif
    </ul>
    @if ($hint)<p class="field-hint">{{ $hint }}</p>@endif
</div>
