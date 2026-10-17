<div class="max-w-5xl">
    @if ($service)
        <div class="mb-6"><x-admin.publish-state :model="$service" /></div>
    @endif

    <form wire:submit="save" data-preserve class="space-y-10">
        <section class="space-y-5" aria-labelledby="sec-public">
            <h2 id="sec-public" class="text-base font-semibold">{{ __('crm.services.public_info') }}</h2>
            <x-admin.bilingual field="name" :label="__('crm.fields.name')" required />
            <x-admin.bilingual field="description" :label="__('crm.fields.description')" textarea />
            <x-admin.bilingual field="preparation" :label="__('crm.fields.preparation')" textarea rows="3" :hint="__('crm.services.preparation_hint')" />
        </section>

        <section class="space-y-5" aria-labelledby="sec-booking">
            <h2 id="sec-booking" class="text-base font-semibold">{{ __('crm.services.booking') }}</h2>
            <p class="-mt-3 max-w-prose text-sm text-muted">{{ __('crm.services.booking_hint') }}</p>
            <div class="grid gap-5 md:grid-cols-3">
                <x-admin.text model="default_duration" type="number" min="5" step="5" :label="__('crm.fields.default_duration_min')" required />
                <x-admin.text model="buffer_before" type="number" min="0" step="5" :label="__('crm.fields.buffer_before')" />
                <x-admin.text model="buffer_after" type="number" min="0" step="5" :label="__('crm.fields.buffer_after')" />
                <x-admin.text model="default_price" inputmode="decimal" :label="__('crm.fields.default_price_lei')" :hint="__('crm.services.price_hint')" />
                <div>
                    <label for="category" class="field-label">{{ __('crm.fields.category') }}</label>
                    <select id="category" wire:model="category" class="input">
                        @foreach (\App\Livewire\Admin\ServiceEditor::CATEGORIES as $c)
                            <option value="{{ $c }}">{{ __('crm.services.categories.'.$c) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="color" class="field-label">{{ __('crm.fields.calendar_color') }}</label>
                    <div class="flex items-center gap-3">
                        <input id="color" type="color" wire:model="color" class="h-11 w-14 cursor-pointer rounded-lg border border-line bg-white p-1">
                        <span class="text-sm text-muted">{{ $color }}</span>
                    </div>
                    @error('color')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </section>

        <section class="space-y-4" aria-labelledby="sec-settings">
            <h2 id="sec-settings" class="text-base font-semibold">{{ __('crm.services.settings') }}</h2>
            <x-admin.toggle model="is_active" :label="__('crm.fields.is_active')" />
            <x-admin.toggle model="is_public" :label="__('crm.fields.is_public')" :hint="__('crm.services.public_hint')" />
            <div class="grid gap-5 md:grid-cols-2">
                <x-admin.text model="code" :label="__('crm.fields.code')" :hint="__('crm.code_hint')" />
                <x-admin.text model="sort_order" type="number" min="0" :label="__('crm.fields.sort_order')" />
            </div>
        </section>

        <x-admin.editor-actions :exists="(bool) $service" back-route="admin.services.index" />
    </form>

    @if ($service)
        <x-admin.delete-zone :label="__('crm.services.delete')" :warning="__('crm.services.delete_warning')" />
    @endif
</div>
