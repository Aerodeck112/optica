<div class="max-w-5xl">
    @if ($specialist)
        <div class="mb-6"><x-admin.publish-state :model="$specialist" /></div>
    @endif

    <form wire:submit="save" data-preserve class="space-y-10">
        <section class="grid gap-8 md:grid-cols-[12rem_minmax(0,1fr)]" aria-labelledby="sec-identity">
            <div>
                <p class="field-label">{{ __('crm.fields.photo') }}</p>
                <div class="aspect-square w-44 overflow-hidden rounded-xl bg-mist ring-1 ring-line">
                    @if ($photo && ! $errors->has('photo') && $photo->isPreviewable())
                        <img src="{{ $photo->temporaryUrl() }}" alt="" class="h-full w-full object-cover">
                    @elseif ($specialist?->photo_path && ! $removePhoto)
                        <img src="{{ Storage::disk('public')->url($specialist->photo_path) }}" alt="" class="h-full w-full object-cover">
                    @else
                        <div class="flex h-full items-center justify-center p-4 text-center text-xs text-muted">{{ __('crm.specialists.no_photo') }}</div>
                    @endif
                </div>
                <label for="photo" class="btn-secondary mt-3 w-44 cursor-pointer">{{ __('crm.specialists.choose_photo') }}</label>
                <input id="photo" type="file" wire:model="photo" accept="image/jpeg,image/png,image/webp" class="sr-only" aria-describedby="photo-hint">
                <p id="photo-hint" class="field-hint w-44">{{ __('crm.specialists.photo_hint') }}</p>
                <div wire:loading wire:target="photo" class="field-hint">{{ __('crm.uploading') }}</div>
                @error('photo')<p class="field-error w-44">{{ $message }}</p>@enderror
                @if ($specialist?->photo_path)
                    <label class="mt-2 flex min-h-11 items-center gap-2 text-sm">
                        <input type="checkbox" wire:model.live="removePhoto" class="h-5 w-5 rounded border-line text-ink focus:ring-ink">
                        {{ __('crm.specialists.remove_photo') }}
                    </label>
                @endif
            </div>
            <div class="space-y-5">
                <h2 id="sec-identity" class="text-base font-semibold">{{ __('crm.specialists.identity') }}</h2>
                <x-admin.text model="display_name" :label="__('crm.fields.display_name')" required :hint="__('crm.specialists.name_hint')" />
                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label for="type" class="field-label">{{ __('crm.fields.type') }}</label>
                        <select id="type" wire:model="type" class="input">
                            @foreach (\App\Models\Specialist::TYPES as $t)
                                <option value="{{ $t }}">{{ __('crm.specialists.types.'.$t) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <fieldset>
                        <legend class="field-label">{{ __('crm.fields.spoken_languages') }}</legend>
                        <div class="flex flex-wrap gap-4">
                            @foreach (['ro', 'hu', 'en', 'de'] as $lang)
                                <label class="flex min-h-11 items-center gap-2 text-sm">
                                    <input type="checkbox" value="{{ $lang }}" wire:model="languages" class="h-5 w-5 rounded border-line text-ink focus:ring-ink">
                                    {{ __('crm.spoken.'.$lang) }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                </div>
            </div>
        </section>

        <section class="space-y-5" aria-labelledby="sec-public">
            <h2 id="sec-public" class="text-base font-semibold">{{ __('crm.specialists.public_profile') }}</h2>
            <x-admin.bilingual field="title" :label="__('crm.fields.professional_title')" :hint="__('crm.specialists.title_hint')" />
            <x-admin.bilingual field="specialties" :label="__('crm.fields.specialties')" textarea rows="3" :hint="__('crm.specialists.specialties_hint')" />
            <x-admin.bilingual field="bio" :label="__('crm.fields.bio')" textarea rows="6" />
        </section>

        <section aria-labelledby="sec-locations">
            <h2 id="sec-locations" class="text-base font-semibold">{{ __('crm.specialists.locations') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('crm.specialists.locations_hint') }}</p>
            <div class="panel mt-4 divide-y divide-line">
                @foreach ($locations as $l)
                    <div class="flex flex-wrap items-center gap-x-8 gap-y-1 px-4 py-2" wire:key="link-{{ $l->id }}">
                        <label class="flex min-h-11 flex-1 items-center gap-3 text-sm font-medium text-heading">
                            <input type="checkbox" wire:model.live="links.{{ $l->id }}.assigned" class="h-5 w-5 rounded border-line text-ink focus:ring-ink">
                            {{ $l->name() }}
                        </label>
                        <label @class(['flex min-h-11 items-center gap-2 text-sm', 'opacity-50' => ! ($links[$l->id]['assigned'] ?? false)])>
                            <input type="checkbox" wire:model="links.{{ $l->id }}.online" class="h-5 w-5 rounded border-line text-ink focus:ring-ink"
                                   @disabled(! ($links[$l->id]['assigned'] ?? false))>
                            {{ __('crm.fields.online_booking') }}
                        </label>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="space-y-4" aria-labelledby="sec-settings">
            <h2 id="sec-settings" class="text-base font-semibold">{{ __('crm.specialists.settings') }}</h2>
            <x-admin.toggle model="is_active" :label="__('crm.fields.is_active')" :hint="__('crm.specialists.active_hint')" />
            <x-admin.toggle model="is_public" :label="__('crm.fields.is_public')" :hint="__('crm.specialists.public_hint')" />
            <div class="grid gap-5 md:grid-cols-2">
                <x-admin.text model="code" :label="__('crm.fields.code')" :hint="__('crm.code_hint')" />
                <x-admin.text model="sort_order" type="number" min="0" :label="__('crm.fields.sort_order')" />
            </div>
        </section>

        <x-admin.editor-actions :exists="(bool) $specialist" back-route="admin.specialists.index" />
    </form>

    @if ($specialist)
        <x-admin.delete-zone :label="__('crm.specialists.delete')" :warning="__('crm.specialists.delete_warning')" />
    @endif
</div>
