<div class="max-w-5xl">
    @if ($location)
        <div class="mb-6 flex flex-wrap items-center gap-3">
            <x-admin.publish-state :model="$location" />
            @if ($location->published_at)
                <span class="text-xs text-muted">{{ __('crm.state.published_at', ['date' => $location->published_at->setTimezone(config('optica.clinic_timezone'))->format('d.m.Y H:i')]) }}</span>
            @endif
        </div>
    @endif

    <form wire:submit="save" data-preserve class="space-y-10">
        <section class="space-y-5" aria-labelledby="sec-public">
            <h2 id="sec-public" class="text-base font-semibold">{{ __('crm.locations.public_info') }}</h2>
            <x-admin.bilingual field="name" :label="__('crm.fields.public_name')" required :hint="__('crm.locations.name_hint')" />
            <x-admin.bilingual field="city" :label="__('crm.fields.city')" />
            <x-admin.bilingual field="address" :label="__('crm.fields.address')" />
            <x-admin.bilingual field="description" :label="__('crm.fields.description')" textarea />
        </section>

        <section class="space-y-5" aria-labelledby="sec-contact">
            <h2 id="sec-contact" class="text-base font-semibold">{{ __('crm.locations.contact_and_map') }}</h2>
            <div class="grid gap-5 md:grid-cols-2">
                <x-admin.text model="city" :label="__('crm.fields.city_official')" required />
                <x-admin.text model="address" :label="__('crm.fields.address_official')" required />
                <x-admin.text model="phone" type="tel" :label="__('crm.fields.phone')" />
                <x-admin.text model="email" type="email" :label="__('crm.fields.email')" />
                <x-admin.text model="latitude" inputmode="decimal" :label="__('crm.fields.latitude')" :hint="__('crm.locations.coordinates_hint')" />
                <x-admin.text model="longitude" inputmode="decimal" :label="__('crm.fields.longitude')" />
            </div>
        </section>

        <section aria-labelledby="sec-hours">
            <h2 id="sec-hours" class="text-base font-semibold">{{ __('crm.locations.shop_hours') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('crm.locations.shop_hours_hint') }}</p>
            <div class="panel mt-4 divide-y divide-line">
                @foreach (range(1, 7) as $day)
                    <div class="grid grid-cols-[7rem_1fr] items-center gap-3 px-4 py-3 sm:grid-cols-[9rem_8rem_1fr]" wire:key="day-{{ $day }}">
                        <span class="text-sm font-medium text-heading">{{ __('crm.weekdays.'.$day) }}</span>
                        <label class="flex min-h-11 items-center gap-2 text-sm">
                            <input type="checkbox" wire:model.live="hours.{{ $day }}.closed" class="h-5 w-5 rounded border-line text-ink focus:ring-ink">
                            {{ __('crm.locations.closed') }}
                        </label>
                        @unless ($hours[$day]['closed'])
                            <div class="col-span-2 flex items-center gap-2 sm:col-span-1">
                                <label class="sr-only" for="open-{{ $day }}">{{ __('crm.fields.opens') }}</label>
                                <input id="open-{{ $day }}" type="time" wire:model="hours.{{ $day }}.open" class="input w-32">
                                <span aria-hidden="true">–</span>
                                <label class="sr-only" for="close-{{ $day }}">{{ __('crm.fields.closes') }}</label>
                                <input id="close-{{ $day }}" type="time" wire:model="hours.{{ $day }}.close" class="input w-32"
                                       @error('hours.'.$day.'.close') aria-invalid="true" @enderror>
                                @error('hours.'.$day.'.close')<p class="field-error">{{ $message }}</p>@enderror
                            </div>
                        @endunless
                    </div>
                @endforeach
            </div>
        </section>

        <section class="space-y-4" aria-labelledby="sec-settings">
            <h2 id="sec-settings" class="text-base font-semibold">{{ __('crm.locations.settings') }}</h2>
            <x-admin.toggle model="is_active" :label="__('crm.fields.is_active')" :hint="__('crm.locations.active_hint')" />
            <x-admin.toggle model="is_public" :label="__('crm.fields.is_public')" :hint="__('crm.locations.public_hint')" />
            <x-admin.toggle model="online_booking_enabled" :label="__('crm.fields.online_booking')" :hint="__('crm.locations.online_hint')" />
            <div class="grid gap-5 md:grid-cols-2">
                <div>
                    <label for="confirmation_mode" class="field-label">{{ __('crm.fields.confirmation_mode') }}</label>
                    <select id="confirmation_mode" wire:model="confirmation_mode" class="input">
                        <option value="reception">{{ __('crm.locations.confirmation.reception') }}</option>
                        <option value="automatic">{{ __('crm.locations.confirmation.automatic') }}</option>
                    </select>
                </div>
                <x-admin.text model="code" :label="__('crm.fields.code')" :hint="__('crm.code_hint')" />
                <x-admin.text model="sort_order" type="number" min="0" :label="__('crm.fields.sort_order')" />
            </div>
        </section>

        <x-admin.editor-actions :exists="(bool) $location" back-route="admin.locations.index" />
    </form>

    @if ($location)
        <div class="mt-12 border-t border-line pt-8">
            <livewire:admin.resource-manager :location="$location" :key="'resources-'.$location->id" />
        </div>
        <x-admin.delete-zone :label="__('crm.locations.delete')" :warning="__('crm.locations.delete_warning')" />
    @endif
</div>
