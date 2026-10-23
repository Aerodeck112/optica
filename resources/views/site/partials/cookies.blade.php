<section class="cookie" id="cookie-banner" aria-labelledby="cookie-title" hidden>
    <h2 id="cookie-title">{{ __('site.cookies.title') }}</h2>
    <p>{{ __('site.cookies.text') }}
        @isset($legalLinks['cookies'])<a href="{{ $legalLinks['cookies']['url'] }}">{{ __('site.cookies.policy') }}</a>@endisset
    </p>
    <div class="cookie__actions">
        <button type="button" class="btn btn--ghost" data-consent="reject">{{ __('site.cookies.reject') }}</button>
        <button type="button" class="btn btn--primary" data-consent="accept">{{ __('site.cookies.accept') }}</button>
        <button type="button" class="btn btn--small cookie__prefs-btn" data-consent="preferences">{{ __('site.cookies.preferences') }}</button>
    </div>
</section>

<dialog class="cookie-dialog" id="cookie-dialog" aria-labelledby="cookie-dialog-title">
    <form method="dialog">
        <h2 id="cookie-dialog-title">{{ __('site.cookies.preferences') }}</h2>
        <div class="cookie-option">
            <label for="cookie-necessary"><strong>{{ __('site.cookies.necessary') }}</strong></label>
            <input class="switch" id="cookie-necessary" type="checkbox" checked disabled>
            <p>{{ __('site.cookies.necessary_text') }}</p>
        </div>
        <div class="cookie-option">
            <label for="cookie-analytics"><strong>{{ __('site.cookies.analytics') }}</strong></label>
            <input class="switch" id="cookie-analytics" name="analytics" type="checkbox">
            <p>{{ __('site.cookies.analytics_text') }}</p>
        </div>
        <div class="cookie-option">
            <label for="cookie-marketing"><strong>{{ __('site.cookies.marketing') }}</strong></label>
            <input class="switch" id="cookie-marketing" name="marketing" type="checkbox">
            <p>{{ __('site.cookies.marketing_text') }}</p>
        </div>
        <div class="cluster mt-5">
            <button type="submit" class="btn btn--primary">{{ __('site.cookies.save') }}</button>
            <button type="button" class="btn btn--ghost" data-dialog-close>{{ __('site.close') }}</button>
        </div>
    </form>
</dialog>
