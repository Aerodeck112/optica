@props(['exists', 'backRoute'])
{{-- Sticky on small screens so saving never requires scrolling back up. --}}
<div class="sticky bottom-0 -mx-4 mt-10 flex flex-wrap items-center gap-3 border-t border-line bg-paper/95 px-4 py-4 backdrop-blur sm:-mx-6 sm:px-6 lg:static lg:mx-0 lg:border-0 lg:bg-transparent lg:px-0">
    <button type="submit" class="btn-secondary" wire:loading.attr="disabled">{{ __('crm.actions.save_draft') }}</button>
    @if ($exists)
        <button type="button" class="btn-primary" wire:click="publish" wire:loading.attr="disabled">{{ __('crm.actions.publish') }}</button>
    @endif
    <a href="{{ route($backRoute) }}" wire:navigate class="btn-link ml-auto text-sm">{{ __('crm.actions.back_to_list') }}</a>
</div>
