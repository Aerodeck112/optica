<form wire:submit="save" class="max-w-3xl space-y-8">
    <p class="max-w-prose text-sm text-muted">{{ __('cms.settings.intro') }}</p>
    <div>
        <label for="phones" class="field-label">{{ __('cms.settings.phones') }} <span class="text-bad" aria-hidden="true">*</span></label>
        <textarea id="phones" wire:model="phones" rows="3" class="input" aria-describedby="phones-hint" @error('phones') aria-invalid="true" @enderror></textarea>
        <p id="phones-hint" class="field-hint">{{ __('cms.settings.phones_hint') }}</p>
        @error('phones')<p class="field-error">{{ $message }}</p>@enderror
    </div>
    <x-admin.text model="contact_email" type="email" :label="__('cms.settings.contact_email')" :hint="__('cms.settings.contact_email_hint')" />
    <fieldset class="grid gap-5 md:grid-cols-2">
        <legend class="mb-2 text-base font-semibold">{{ __('cms.settings.factory') }}</legend>
        <x-admin.text model="factory_phone" :label="__('crm.fields.phone')" />
        <x-admin.text model="factory_email" type="email" :label="__('crm.fields.email')" />
    </fieldset>
    <button type="submit" class="btn-primary">{{ __('crm.actions.save') }}</button>
</form>
