{{-- Un copil în declarația pentru familie: doar numele, data nașterii și, opțional, nickname-ul. --}}
<div class="family-child" data-family-child>
    <div class="field">
        <label class="field__label" for="child-{{ $i }}-name">{{ __('site.waiver.family.child_name') }}</label>
        <input class="input" id="child-{{ $i }}-name" name="children[{{ $i }}][name]" value="{{ $child['name'] ?? '' }}" required autocomplete="off" @error("children.$i.name") aria-invalid="true" @enderror>
        @error("children.$i.name")<p class="field__error">{{ $message }}</p>@enderror
    </div>
    <div class="field">
        <label class="field__label" for="child-{{ $i }}-birth">{{ __('site.waiver.birth_date') }}</label>
        <input class="input" type="date" id="child-{{ $i }}-birth" name="children[{{ $i }}][birth_date]" value="{{ $child['birth_date'] ?? '' }}" max="{{ $maxDate }}" required data-family-birth @error("children.$i.birth_date") aria-invalid="true" @enderror>
        @error("children.$i.birth_date")<p class="field__error">{{ $message }}</p>@enderror
        <p class="field__error" data-family-adult hidden>{{ \Illuminate\Support\Str::ucfirst(trim(__('site.waiver.family.child_adult', ['name' => '']))) }}</p>
    </div>
    <div class="field">
        <label class="field__label" for="child-{{ $i }}-nick">{{ __('site.waiver.family.nickname_optional') }} <span class="muted">({{ __('site.forms.optional') }})</span></label>
        <input class="input" id="child-{{ $i }}-nick" name="children[{{ $i }}][nickname]" value="{{ $child['nickname'] ?? '' }}" maxlength="18" autocomplete="off" @error("children.$i.nickname") aria-invalid="true" @enderror>
        @error("children.$i.nickname")<p class="field__error">{{ $message }}</p>@enderror
    </div>
    <button type="button" class="btn btn--small btn--ghost family-child__remove" data-family-remove>{{ __('site.waiver.family.remove_child') }}</button>
</div>
