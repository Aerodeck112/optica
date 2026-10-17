@props(['model'])
@if (! $model?->published_at)
    <span class="badge-neutral">{{ __('crm.state.never_published') }}</span>
@elseif ($model->hasUnpublishedChanges())
    <span class="badge-warn">{{ __('crm.state.unpublished_changes') }}</span>
@else
    <span class="badge-ok">{{ __('crm.state.published') }}</span>
@endif
