@props(['model', 'label', 'hint' => null])
@php($id = 'toggle-'.str_replace('.', '-', $model))
<div class="flex items-start gap-3">
    <input id="{{ $id }}" type="checkbox" wire:model="{{ $model }}" class="mt-0.5 h-5 w-5 shrink-0 rounded border-line text-ink focus:ring-ink"
           @if ($hint) aria-describedby="{{ $id }}-hint" @endif>
    <div>
        <label for="{{ $id }}" class="text-sm font-medium text-heading">{{ $label }}</label>
        @if ($hint)<p id="{{ $id }}-hint" class="text-xs text-muted">{{ $hint }}</p>@endif
    </div>
</div>
