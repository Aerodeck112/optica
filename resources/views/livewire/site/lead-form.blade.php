<div>
    @if ($sent)
        <div class="rounded-2xl bg-ok-bg p-6 text-ok" role="status">
            <p class="text-lg font-semibold">{{ __('site.form.sent_title') }}</p>
            <p class="mt-1">{{ __('site.form.sent_text') }}</p>
        </div>
    @else
        <form wire:submit="submit" class="space-y-5" novalidate>
            @error('form')<p class="rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad" role="alert">{{ $message }}</p>@enderror
            <div class="hidden" aria-hidden="true"><label>Website <input type="text" wire:model="website" tabindex="-1" autocomplete="off"></label></div>
            <div class="grid gap-5 sm:grid-cols-2">
                <x-admin.text model="name" :label="__('site.booking.name')" required autocomplete="name" />
                @if ($type === 'b2b')
                    <x-admin.text model="company" :label="__('site.form.company')" required autocomplete="organization" />
                @endif
                <x-admin.text model="phone" type="tel" :label="__('site.booking.phone')" autocomplete="tel" />
                <x-admin.text model="email" type="email" :label="__('site.form.email')" autocomplete="email" />
                @if ($locations->isNotEmpty())
                    <div>
                        <label for="lead-location" class="field-label">{{ __('site.form.location') }}</label>
                        <select id="lead-location" wire:model="locationId" class="input">
                            <option value="">{{ __('site.form.any_location') }}</option>
                            @foreach ($locations as $l)<option value="{{ $l->id }}">{{ $l->publicValue('name') ?? $l->name() }}</option>@endforeach
                        </select>
                    </div>
                @endif
            </div>
            <p class="-mt-2 text-xs text-muted">{{ __('site.form.phone_or_email') }}</p>
            <div>
                <label for="lead-message" class="field-label">{{ __('site.form.message') }} <span class="text-bad" aria-hidden="true">*</span></label>
                <textarea id="lead-message" wire:model="message" rows="4" maxlength="3000" class="input" aria-describedby="lead-message-hint" @error('message') aria-invalid="true" @enderror></textarea>
                <p id="lead-message-hint" class="field-hint">{{ __('site.booking.message_hint') }}</p>
                @error('message')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <label class="flex items-start gap-3 text-sm">
                <input type="checkbox" wire:model="privacy" class="mt-0.5 h-5 w-5 shrink-0 rounded border-line text-ink focus:ring-ink">
                <span>{!! __('site.form.privacy', ['link' => '<a class="btn-link" target="_blank" href="'.e(\App\Support\Ui\SiteUrl::path('privacy-policy')).'">'.e(__('site.footer.privacy')).'</a>']) !!}</span>
            </label>
            @error('privacy')<p class="field-error">{{ __('site.booking.privacy_required') }}</p>@enderror
            <label class="flex items-start gap-3 text-sm">
                <input type="checkbox" wire:model="marketingEmail" class="mt-0.5 h-5 w-5 shrink-0 rounded border-line text-ink focus:ring-ink">
                <span>{{ __('site.form.marketing_email') }}</span>
            </label>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ __('site.form.send') }}</button>
        </form>
    @endif
</div>
