@props(['model', 'label', 'type' => 'text', 'hint' => null, 'required' => false])
@php($id = 'f-'.str_replace('.', '-', $model))
<div>
    <label for="{{ $id }}" class="field-label">{{ $label }}@if ($required) <span class="text-bad" aria-hidden="true">*</span>@endif</label>
    <input id="{{ $id }}" type="{{ $type }}" wire:model="{{ $model }}" {{ $attributes->merge(['class' => 'input']) }}
           @if ($required) required @endif
           aria-describedby="{{ $hint ? $id.'-hint' : '' }} @error($model) {{ $id }}-error @enderror"
           @error($model) aria-invalid="true" @enderror>
    @if ($hint)<p id="{{ $id }}-hint" class="field-hint">{{ $hint }}</p>@endif
    @error($model)<p id="{{ $id }}-error" class="field-error">{{ $message }}</p>@enderror
</div>
