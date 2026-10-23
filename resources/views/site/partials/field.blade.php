@php
    $fieldId = $id ?? 'f-'.$name;
    $error = $errors->first($name);
    $hintId = $fieldId.'-hint';
    $errorId = $fieldId.'-error';
    $describedBy = trim((! empty($hint) ? $hintId : '').' '.($error ? $errorId : ''));
@endphp
<div class="field">
    <label class="field__label" for="{{ $fieldId }}">{{ $label }}@if (! ($required ?? false)) <span class="muted">({{ __('site.forms.optional') }})</span>@endif</label>
    @if (($type ?? 'text') === 'textarea')
        <textarea class="textarea" id="{{ $fieldId }}" name="{{ $name }}" @if ($required ?? false) required @endif @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($error) aria-invalid="true" @endif maxlength="{{ $max ?? 2000 }}">{{ old($name, $value ?? '') }}</textarea>
    @elseif (($type ?? 'text') === 'select')
        <select class="select" id="{{ $fieldId }}" name="{{ $name }}" @if ($required ?? false) required @endif @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($error) aria-invalid="true" @endif>
            @foreach ($options as $optValue => $optLabel)
                <option value="{{ $optValue }}" @selected(old($name, $value ?? '') == $optValue)>{{ $optLabel }}</option>
            @endforeach
        </select>
    @else
        <input class="input" id="{{ $fieldId }}" type="{{ $type ?? 'text' }}" name="{{ $name }}" value="{{ old($name, $value ?? '') }}"
               @if ($required ?? false) required @endif
               @isset($autocomplete) autocomplete="{{ $autocomplete }}" @endisset
               @isset($min) min="{{ $min }}" @endisset @isset($maxValue) max="{{ $maxValue }}" @endisset
               @isset($inputmode) inputmode="{{ $inputmode }}" @endisset
               @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif @if ($error) aria-invalid="true" @endif>
    @endif
    @if (! empty($hint))<p class="field__hint" id="{{ $hintId }}">{{ $hint }}</p>@endif
    @if ($error)<p class="field__error" id="{{ $errorId }}">{{ $error }}</p>@endif
</div>
