@props(['field', 'label', 'textarea' => false, 'rows' => 4, 'hint' => null, 'required' => false])
{{-- Romanian and Hungarian side by side, so both versions are reviewed together (cap. 8). --}}
<fieldset class="grid gap-4 md:grid-cols-2">
    <legend class="field-label mb-2">{{ $label }}@if ($required) <span class="text-bad" aria-hidden="true">*</span>@endif</legend>
    @foreach (config('optica.locales') as $locale)
        @php($id = 'content-'.$locale.'-'.$field)
        @php($key = "content.$locale.$field")
        <div>
            <label for="{{ $id }}" class="mb-1 block text-xs font-medium text-muted">{{ __('crm.languages.'.$locale) }}</label>
            @if ($textarea)
                <textarea id="{{ $id }}" wire:model="{{ $key }}" rows="{{ $rows }}" lang="{{ $locale }}" class="input"
                          @error($key) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror></textarea>
            @else
                <input id="{{ $id }}" type="text" wire:model="{{ $key }}" lang="{{ $locale }}" class="input"
                       @error($key) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror>
            @endif
            @error($key)<p id="{{ $id }}-error" class="field-error">{{ $message }}</p>@enderror
        </div>
    @endforeach
    @if ($hint)<p class="field-hint md:col-span-2 -mt-2">{{ $hint }}</p>@endif
</fieldset>
