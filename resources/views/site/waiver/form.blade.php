@extends('site.layout')

@section('content')
@if ($tablet ?? null)
    <div class="tablet-bar" data-tablet-idle="{{ min($tablet['idle'], 360) }}">
        <img src="{{ asset('media/brand/aw-logo-horizontal.svg') }}" alt="Another World Cluj" width="180" height="40">
        <form method="post" action="{{ $tablet['cancel'] }}" data-confirm="{{ __('site.waiver.tablet.cancel_confirm') }}" data-tablet-cancel>
            @csrf
            <button type="submit" class="btn btn--small btn--ghost">{{ __('site.waiver.tablet.cancel') }}</button>
        </form>
    </div>
@endif
<header class="page-hero">
    <div class="wrap">
        <h1>{{ __('site.waiver.title') }}</h1>
        <p class="hero__lede">{{ __('site.waiver.intro') }}</p>
    </div>
</header>
<section class="section section--tight">
    <div class="wrap--narrow stack">
        @if (session('waiver_signed'))
            <div class="alert alert--ok" role="status" tabindex="-1">
                <p><strong>{{ __('site.waiver.signed', ['name' => session('waiver_signed')]) }}</strong></p>
                <p>{{ __('site.waiver.next') }}</p>
            </div>
        @endif

        @if ($booking)
            <div class="notice">
                <x-aw-icon name="calendar"/>
                <div class="stack">
                    <p><strong>{{ __('site.waiver.booking', ['code' => $booking->code, 'date' => $booking->starts_at ? \App\Support\LocalTime::format($booking->starts_at, 'l, j F Y, H:i') : '—']) }}</strong></p>
                    <p>{{ __('site.waiver.progress', ['signed' => $progress['signed'], 'expected' => $progress['expected']]) }}</p>
                    @if ($booking->promoCode?->per_participant)
                        <p>{{ __('site.waiver.offer_note', ['code' => $booking->promoCode->code]) }}</p>
                    @endif
                    @unless ($tablet ?? null)
                    <p>
                        <button type="button" class="btn btn--small btn--ghost" data-copy-url="{{ url()->full() }}">{{ __('site.waiver.copy') }}</button>
                        <span class="small muted" data-copy-status hidden>{{ __('site.waiver.copied') }}</span>
                    </p>
                    @endunless
                </div>
            </div>
        @endif

        @if (! $document)
            <div class="notice"><x-aw-icon name="info"/><p>{{ __('site.waiver.unavailable') }}</p></div>
        @else
            <details class="panel" @if (! session('waiver_signed')) open @endif>
                <summary><strong>{{ __('site.waiver.read') }}</strong> <span class="small muted">· {{ $document->getTranslation('title', app()->getLocale()) }} · {{ __('site.waiver.version', ['version' => $document->version]) }}</span></summary>
                <div class="prose mt-5 waiver-text">{!! $body !!}</div>
            </details>

            @if ($errors->any())
                <div class="alert alert--error" role="alert" tabindex="-1">
                    <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <form class="panel" method="post" action="{{ $action }}" data-guard data-waiver data-session-date="{{ $sessionDate }}" novalidate>
                @csrf
                <input type="hidden" name="locale" value="{{ app()->getLocale() }}">
                @include('site.partials.form-guard', ['id' => 'waiver'])

                @php
                    $prefill = $prefill ?? [];
                    $children = array_values((array) old('children', []));
                    $selfChecked = old('mode') ? (bool) old('self') : true;
                    $maxDate = now()->toDateString();
                @endphp
                <input type="hidden" name="mode" value="family">
                @if ($prefill)
                    <div class="notice mt-2"><x-aw-icon name="info"/><p>{{ __('site.waiver.family.prefill_note') }}</p></div>
                @endif

                <h2 class="mt-5" style="font-size: var(--fs-lg)">{{ __('site.waiver.family.who_signs') }}</h2>
                <p class="small muted mt-2">{{ __('site.waiver.family.who_signs_note') }}</p>
                <div class="mt-5">
                    @include('site.partials.field', ['name' => 'signer_name', 'label' => __('site.waiver.signer_name'), 'required' => true, 'id' => 'waiver-signer', 'autocomplete' => 'name', 'value' => $prefill['signer_name'] ?? ''])
                </div>
                <div class="field-row mt-5">
                    @include('site.partials.field', ['name' => 'guardian_phone', 'type' => 'tel', 'label' => __('site.waiver.family.phone'), 'id' => 'waiver-phone', 'autocomplete' => 'tel', 'value' => $prefill['phone'] ?? ''])
                    @include('site.partials.field', ['name' => 'email', 'type' => 'email', 'label' => __('site.waiver.email'), 'id' => 'waiver-email', 'autocomplete' => 'email', 'value' => $prefill['email'] ?? ''])
                </div>
                @unless ($booking || ($tablet ?? null))
                    <div class="mt-5">@include('site.partials.field', ['name' => 'booking_code', 'label' => __('site.waiver.booking_code'), 'id' => 'waiver-booking', 'autocomplete' => 'off'])</div>
                @endunless

                <h2 class="mt-7" style="font-size: var(--fs-lg)">{{ __('site.waiver.family.who_plays') }}</h2>
                @error('self')<p class="field__error mt-2" role="alert">{{ $message }}</p>@enderror
                <label class="check mt-4 family-self"><input type="checkbox" name="self" value="1" data-family-self @checked($selfChecked)><span><strong>{{ __('site.waiver.family.self') }}</strong></span></label>
                <div class="field-row mt-3" data-family-self-fields @unless ($selfChecked) hidden @endunless>
                    @include('site.partials.field', ['name' => 'signer_birth_date', 'type' => 'date', 'label' => __('site.waiver.family.self_birth'), 'required' => true, 'id' => 'waiver-self-birth', 'maxValue' => $maxDate])
                    @include('site.partials.field', ['name' => 'self_nickname', 'label' => __('site.waiver.family.nickname_optional'), 'id' => 'waiver-self-nick', 'autocomplete' => 'off'])
                </div>

                <fieldset class="mt-6 family-children" style="border:0;padding:0">
                    <legend class="field__label">{{ __('site.waiver.family.children_title') }}</legend>
                    <p class="small muted mt-2">{{ __('site.waiver.family.children_note') }}</p>
                    <div class="stack mt-4" data-family-children data-session-date="{{ $sessionDate }}">
                        @foreach ($children as $i => $child)
                            @include('site.waiver.child-row', ['i' => $i, 'child' => $child, 'maxDate' => $maxDate])
                        @endforeach
                    </div>
                    <template data-family-child-template>
                        @include('site.waiver.child-row', ['i' => '__I__', 'child' => [], 'maxDate' => $maxDate])
                    </template>
                    <button type="button" class="btn btn--ghost mt-4" data-family-add>{{ __('site.waiver.family.add_child') }}</button>
                    <div class="mt-4" data-family-relationship @if (! $children) hidden @endif>
                        @include('site.partials.field', ['name' => 'guardian_relationship', 'label' => __('site.waiver.family.relationship'), 'id' => 'waiver-relationship', 'value' => __('site.waiver.family.relationship_default')])
                    </div>
                </fieldset>

                <p class="small muted mt-6">{{ __('site.waiver.player_note') }}</p>
                <label class="check mt-3"><input type="checkbox" name="screen_display" value="1" @checked(old('screen_display'))><span>{{ __('site.waiver.family.screen_display') }}</span></label>

                <h2 class="mt-7" style="font-size: var(--fs-lg)">{{ __('site.waiver.signature_title') }}</h2>
                <label class="check mt-5"><input type="checkbox" name="accept" value="1" required @checked(old('accept'))><span>{{ __('site.waiver.accept') }}</span></label>
                <div class="field mt-5" data-signature>
                    <span class="field__label" id="waiver-signature-label">{{ __('site.waiver.signature') }}</span>
                    <canvas class="signature-pad" width="600" height="220" role="img" aria-labelledby="waiver-signature-label" aria-describedby="waiver-signature-hint"></canvas>
                    <input type="hidden" name="signature" value="">
                    <div class="cluster mt-3">
                        <p class="field__hint" id="waiver-signature-hint">{{ __('site.waiver.signature_hint') }}</p>
                        <button type="button" class="btn btn--small btn--ghost" data-signature-clear>{{ __('site.waiver.signature_clear') }}</button>
                    </div>
                    <p class="field__error" data-signature-error hidden role="alert">{{ __('site.waiver.signature_missing') }}</p>
                </div>

                <fieldset class="mt-6" style="border:0;padding:0">
                    <legend class="field__label">{{ __('site.waiver.optional') }}</legend>
                    <label class="check mt-4"><input type="checkbox" name="marketing_email" value="1" @checked(old('marketing_email'))><span>{{ __('site.waiver.marketing') }}</span></label>
                    <label class="check" data-photo-adult><input type="checkbox" name="photo_consent" value="1" @checked(old('photo_consent'))><span>{{ __('site.waiver.photo') }}</span></label>
                    <p class="field__hint mt-3">{{ __('site.waiver.photo_minor') }}</p>
                </fieldset>

                <p class="small muted mt-5">
                    {{ __('site.waiver.privacy') }}
                    @if ($privacy)
                        {!! __('site.waiver.privacy_link', ['link' => '<a href="'.e(lroute('legal.show', ['slug' => $privacy->getTranslation('slug', app()->getLocale(), true)])).'">'.e($privacy->getTranslation('title', app()->getLocale(), false) ?: __('site.legal_titles.privacy')).'</a>']) !!}
                    @endif
                </p>
                <button type="submit" class="btn btn--primary mt-6" data-busy="{{ __('site.waiver.busy') }}">{{ __('site.waiver.submit') }}</button>
            </form>
        @endif
    </div>
</section>
@endsection
