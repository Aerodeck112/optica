@props(['label', 'warning'])
<section class="mt-12 border-t border-line pt-8" aria-labelledby="delete-heading" x-data="{ confirming: false }">
    <h2 id="delete-heading" class="text-base font-semibold">{{ $label }}</h2>
    <p class="mt-1 max-w-prose text-sm text-muted">{{ $warning }}</p>
    @error('delete')<p class="field-error" role="alert">{{ $message }}</p>@enderror
    <div class="mt-4 flex flex-wrap gap-3">
        <button type="button" class="btn-danger" x-show="!confirming" @click="confirming = true">{{ $label }}</button>
        <template x-if="confirming">
            <div class="flex flex-wrap items-center gap-3" role="alertdialog" aria-labelledby="delete-confirm-text">
                <p id="delete-confirm-text" class="text-sm font-medium text-bad">{{ __('crm.actions.confirm_delete') }}</p>
                <button type="button" class="btn-danger" wire:click="delete">{{ __('crm.actions.yes_delete') }}</button>
                <button type="button" class="btn-secondary" @click="confirming = false">{{ __('crm.actions.cancel') }}</button>
            </div>
        </template>
    </div>
</section>
