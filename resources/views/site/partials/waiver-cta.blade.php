{{-- Linkul către declarația de participare a rezervării, cu progresul (fără date personale). --}}
@php $waiverProgress = app(\App\Modules\Booking\Services\PlayerWaiverService::class)->progress($booking); @endphp
<div class="panel stack">
    <h2 style="font-size: var(--fs-lg)">{{ __('site.waiver.title') }}</h2>
    <p class="muted">{{ __('site.waiver.cta_text') }}</p>
    <p class="small">{{ __('site.waiver.progress', ['signed' => $waiverProgress['signed'], 'expected' => $waiverProgress['expected']]) }}</p>
    <div class="cluster">
        <a class="btn btn--primary" href="{{ $booking->waiverUrl() }}">{{ __('site.waiver.cta') }}</a>
        <button type="button" class="btn btn--ghost" data-copy-url="{{ $booking->waiverUrl() }}">{{ __('site.waiver.copy') }}</button>
        <span class="small muted" data-copy-status hidden>{{ __('site.waiver.copied') }}</span>
    </div>
</div>
