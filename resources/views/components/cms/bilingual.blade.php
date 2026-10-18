@props(['base', 'field', 'label', 'textarea' => false, 'rows' => 4, 'hint' => null, 'required' => false, 'max' => null])
{{-- RO and HU side by side; $base is the Livewire path that holds the per-language arrays. --}}
<fieldset class="grid gap-4 md:grid-cols-2">
    <legend class="field-label mb-2">{{ $label }}@if ($required) <span class="text-bad" aria-hidden="true">*</span>@endif</legend>
    @foreach (config('optica.locales') as $locale)
        @php($key = $field === "" ? "$base.$locale" : "$base.$locale.$field")
        @php($id = 'f-'.str_replace('.', '-', $key))
        <div>
            <label for="{{ $id }}" class="mb-1 block text-xs font-medium text-muted">{{ __('crm.languages.'.$locale) }}</label>
            @if ($textarea)
                <textarea id="{{ $id }}" wire:model.blur="{{ $key }}" rows="{{ $rows }}" lang="{{ $locale }}" class="input" @if ($max) maxlength="{{ $max }}" @endif
                          @error($key) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror></textarea>
            @else
                <input id="{{ $id }}" type="text" wire:model.blur="{{ $key }}" lang="{{ $locale }}" class="input" @if ($max) maxlength="{{ $max }}" @endif
                       @error($key) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror>
            @endif
            @error($key)<p id="{{ $id }}-error" class="field-error">{{ $message }}</p>@enderror
        </div>
    @endforeach
    @if ($hint)<p class="field-hint -mt-2 md:col-span-2">{{ $hint }}</p>@endif
</fieldset>
