<div class="max-w-5xl">
    @if ($offer)
        <div class="mb-6 flex flex-wrap items-center gap-3">
            @if (! $offer->published_at)<span class="badge-neutral">{{ __('crm.state.never_published') }}</span>
            @elseif ($this->hasUnpublishedChanges())<span class="badge-warn">{{ __('crm.state.unpublished_changes') }}</span>
            @else<span class="badge-ok">{{ __('crm.state.published') }}</span>@endif
            @if ($offer->published_at)
                <a href="{{ \App\Support\Ui\SiteUrl::path('reduceri-actuale/'.$offer->slug_ro, 'ro') }}" target="_blank" rel="noopener" class="btn-link text-sm">{{ __('cms.offers.view_on_site') }}</a>
            @endif
        </div>
    @endif

    <form wire:submit="save" class="space-y-10">
        <section class="space-y-5">
            <x-admin.bilingual field="title" :label="__('cms.offers.fields.title')" required />
            <x-admin.bilingual field="summary" :label="__('cms.offers.fields.summary')" textarea rows="2" :hint="__('cms.offers.summary_hint')" />
            <x-admin.bilingual field="body" :label="__('cms.offers.fields.body')" textarea rows="10" :hint="__('cms.fields.body_hint')" />
        </section>

        <section class="space-y-5">
            <h2 class="text-base font-semibold">{{ __('cms.offers.period') }}</h2>
            <p class="-mt-3 max-w-prose text-sm text-muted">{{ __('cms.offers.period_hint') }}</p>
            <div class="grid gap-5 md:grid-cols-2">
                <x-admin.text model="starts_on" type="date" :label="__('cms.offers.starts_on')" />
                <x-admin.text model="ends_on" type="date" :label="__('cms.offers.ends_on')" />
                @can('seo.manage')
                    <x-admin.text model="slug_ro" :label="__('cms.pages.slug').' (RO)'" :hint="__('cms.offers.slug_hint')" />
                    <x-admin.text model="slug_hu" :label="__('cms.pages.slug').' (HU)'" />
                @endcan
            </div>
        </section>

        <section>
            <p class="field-label">{{ __('cms.fields.image') }}</p>
            <div class="flex flex-wrap items-end gap-4">
                @if ($image)
                    <img src="{{ $image->url(480) }}" alt="{{ $image->altText() }}" class="aspect-[4/3] w-40 rounded-lg object-cover ring-1 ring-line">
                    <button type="button" class="btn-link text-sm text-bad" wire:click="$set('image_id', null)">{{ __('cms.media.remove') }}</button>
                @endif
                <button type="button" class="btn-secondary" wire:click="$toggle('picking')" aria-expanded="{{ $picking ? 'true' : 'false' }}">{{ __('cms.media.choose') }}</button>
            </div>
            @if ($picking)
                <ul class="mt-4 grid grid-cols-3 gap-3 sm:grid-cols-5" role="list">
                    @foreach ($library as $m)
                        <li wire:key="pick-{{ $m->id }}">
                            <button type="button" wire:click="pick({{ $m->id }})" class="block w-full overflow-hidden rounded-lg ring-1 ring-line hover:ring-2 hover:ring-ink">
                                <img src="{{ $m->url(480) }}" alt="{{ $m->altText() }}" class="aspect-[4/3] w-full object-cover" loading="lazy">
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <div class="sticky bottom-0 -mx-4 flex flex-wrap items-center gap-3 border-t border-line bg-paper/95 px-4 py-4 backdrop-blur sm:-mx-6 sm:px-6 lg:static lg:mx-0 lg:border-0 lg:bg-transparent lg:px-0">
            <button type="submit" class="btn-secondary" wire:loading.attr="disabled">{{ __('crm.actions.save_draft') }}</button>
            @can('cms.publish')
                <button type="button" class="btn-primary" wire:click="publish" wire:loading.attr="disabled">{{ __('crm.actions.publish') }}</button>
                @if ($offer?->published_at)
                    <button type="button" class="btn-link text-sm" wire:click="unpublish" wire:confirm="{{ __('cms.offers.confirm_unpublish') }}">{{ __('cms.offers.unpublish') }}</button>
                @endif
            @endcan
            <a href="{{ route('cms.offers.index') }}" wire:navigate class="btn-link ml-auto text-sm">{{ __('crm.actions.back_to_list') }}</a>
        </div>
    </form>

    @if ($offer)
        @can('cms.publish')
            <x-admin.delete-zone :label="__('cms.offers.delete')" :warning="__('cms.offers.delete_warning')" />
        @endcan
    @endif
</div>
