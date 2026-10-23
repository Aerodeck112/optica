{{-- Protecție anti-spam accesibilă: câmp ascuns pentru roboți + timp minim de completare. Fără CAPTCHA vizual. --}}
<div class="hp-field" aria-hidden="true">
    <label for="website-{{ $id ?? 'f' }}">Lăsați acest câmp gol</label>
    <input type="text" id="website-{{ $id ?? 'f' }}" name="website" tabindex="-1" autocomplete="off">
</div>
<input type="hidden" name="_started" value="{{ now()->timestamp }}">
@if ($turnstile = filled_setting('site.anti_spam_turnstile_site_key'))
    <div class="cf-turnstile" data-sitekey="{{ $turnstile }}" data-theme="dark"></div>
    @once
        @push('preload')<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer nonce="{{ csp_nonce() }}"></script>@endpush
    @endonce
@endif
