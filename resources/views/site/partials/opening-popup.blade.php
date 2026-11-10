{{-- Oferta de deschidere: apare o dată pe zi; se închide cu X, „Mai târziu”, Esc sau clic în afara ei. --}}
<dialog class="open-pop" id="opening-popup" data-opening-popup data-key="{{ $pop['key'] }}" aria-labelledby="open-pop-title">
    <button type="button" class="open-pop__close" data-pop-close aria-label="{{ __('site.popup.close') }}"><x-aw-icon name="close"/></button>
    <div class="open-pop__grid">
        <div class="open-pop__date" aria-hidden="true">
            <span class="open-pop__ring"></span>
            <span class="open-pop__weekday">{{ __('site.popup.weekday') }}</span>
            <span class="open-pop__day">{{ $pop['day'] }}</span>
            <span class="open-pop__month">{{ __('site.popup.month') }}</span>
        </div>
        <div class="open-pop__copy">
            <h2 id="open-pop-title" class="open-pop__title">
                @if ($pop['upcoming'])
                    {{ __('site.popup.title_1') }}<span>{{ __('site.popup.title_2') }}</span>
                @else
                    {{ __('site.popup.title_open') }}
                @endif
            </h2>
            <p class="open-pop__offer">{{ __('site.popup.offer') }}</p>
            <div class="open-pop__ticket">
                <span class="open-pop__label">{{ __('site.promo.code_label') }}</span>
                <span class="open-pop__code">{{ $pop['code'] }}</span>
                <button type="button" class="promo__copy" data-copy-url="{{ $pop['code'] }}"><x-aw-icon name="copy"/><span>{{ __('site.promo.copy') }}</span></button>
                <span class="promo__copied small" data-copy-status hidden>{{ __('site.promo.copied') }}</span>
            </div>
            <div class="open-pop__actions">
                <a class="btn btn--primary" href="{{ $pop['url'] }}" data-track-click="cta_book" data-track-params='{"position":"opening_popup"}'>{{ __('site.popup.cta') }}</a>
                <button type="button" class="btn btn--ghost" data-pop-close>{{ __('site.popup.later') }}</button>
            </div>
            @if ($pop['until'])<p class="open-pop__terms small">{{ __('site.popup.terms', ['date' => $pop['until']]) }}</p>@endif
        </div>
    </div>
</dialog>
