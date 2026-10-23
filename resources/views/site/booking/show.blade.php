@extends('site.layout')

@php
    $locale = app()->getLocale();
    $selectedId = $selected?->id;
@endphp

@section('content')
<header class="page-hero">
    <div class="wrap">
        <h1>{{ __('site.booking.title') }}</h1>
        <p>{{ __('site.booking.intro') }}</p>
    </div>
</header>

<section class="section section--tight">
    <div class="wrap">
        @if (! $onlineEnabled)
            <div class="alert alert--warn" role="status">{{ __('site.booking.online_disabled') }}</div>
        @endif

        @if ($packages->isEmpty())
            <div class="notice"><x-aw-icon name="info"/><p>{{ __('site.booking.nothing_configured') }}</p></div>
            @include('site.blocks.lead_form', ['b' => ['type' => 'group', 'heading' => null, 'intro' => null, 'anchor' => 'cerere'], 'blockIndex' => 0])
        @else
        <div class="booking" data-booking>
            <script type="application/json" data-booking-config nonce="{{ csp_nonce() }}">{!! aw_json($config) !!}</script>

            <form method="post" action="{{ route('forms.booking') }}" data-guard novalidate>
                @csrf
                <input type="hidden" name="locale" value="{{ $locale }}">
                <input type="hidden" name="starts_at" value="{{ old('starts_at') }}">
                <input type="hidden" name="hold_id" value="">
                <input type="hidden" name="hold_token" value="">
                @if ($game)<input type="hidden" name="game_id" value="{{ $game->id }}">@endif
                @include('site.partials.form-guard', ['id' => 'booking'])

                <ol class="booking__steps" role="list" aria-label="{{ __('site.booking.steps_label') }}">
                    <li data-step-indicator="experience">{{ __('site.booking.step_experience') }}</li>
                    <li data-step-indicator="group">{{ __('site.booking.step_group') }}</li>
                    <li data-step-indicator="time">{{ __('site.booking.step_time') }}</li>
                    <li data-step-indicator="contact">{{ __('site.booking.step_contact') }}</li>
                    <li data-step-indicator="summary">{{ __('site.booking.step_summary') }}</li>
                </ol>

                @if ($errors->any())
                    <div class="alert alert--error" role="alert" tabindex="-1" id="booking-errors">
                        <p><strong>{{ __('site.booking.error_generic') }}</strong></p>
                        <ul class="small mt-4">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif

                <fieldset class="booking-step" style="border:0;padding-inline:0;margin:0">
                    <legend><h2>{{ __('site.booking.choose_experience') }}</h2></legend>
                    <div class="choice-grid mt-5">
                        @foreach ($packages as $package)
                            <label class="choice">
                                <input type="radio" name="package_id" value="{{ $package->id }}" @checked($selectedId === $package->id) required>
                                <span class="choice__title">{{ $package->name }}</span>
                                <span class="choice__meta">
                                    @if ($package->hasPrice()){{ money($package->price_minor) }}@if ($package->pricing_model === 'per_person') {{ __('site.packages.per_person') }}@endif@else{{ __('site.packages.price_on_request') }}@endif
                                    @if ($package->slot_minutes) — {{ __('site.packages.slot', ['minutes' => $package->slot_minutes]) }}@endif
                                </span>
                                @if ($package->summary)<span class="choice__meta">{{ $package->summary }}</span>@endif
                            </label>
                        @endforeach
                    </div>
                    @error('package_id')<p class="field__error mt-4">{{ $message }}</p>@enderror
                </fieldset>

                <fieldset class="booking-step" style="border:0;padding-inline:0;margin:0">
                    <legend><h2>{{ __('site.booking.participants') }}</h2></legend>
                    <div class="stepper-input mt-5">
                        <button type="button" data-step-btn="-1" aria-label="{{ __('site.booking.fewer') }}">−</button>
                        <label class="visually-hidden" for="participants">{{ __('site.booking.summary_participants') }}</label>
                        <input id="participants" type="number" name="participants" inputmode="numeric" min="{{ $selected?->min_participants ?? 1 }}" max="{{ $selected?->max_participants ?? 50 }}" value="{{ old('participants', max(2, $selected?->min_participants ?? 2)) }}" required aria-describedby="participants-hint">
                        <button type="button" data-step-btn="1" aria-label="{{ __('site.booking.more') }}">+</button>
                    </div>
                    <p class="field__hint mt-4" id="participants-hint" data-participants-hint>@if ($selected){{ __('site.booking.participants_hint', ['min' => $selected->min_participants ?: 1, 'max' => $selected->max_participants ?: 50]) }}@endif</p>
                    @error('participants')<p class="field__error">{{ $message }}</p>@enderror

                    @if ($ageGroups)
                        <div class="mt-6">
                            <p class="field__label">{{ __('site.booking.age_groups') }}</p>
                            <p class="field__hint">{{ __('site.booking.age_groups_hint') }}</p>
                            <div class="field-row mt-4">
                                @foreach ($ageGroups as $key => $label)
                                    <div class="field">
                                        <label class="field__label" for="age-{{ $key }}">{{ $label }}</label>
                                        <input class="input" id="age-{{ $key }}" type="number" min="0" max="200" inputmode="numeric" name="age_groups[{{ $key }}]" value="{{ old('age_groups.'.$key) }}" data-age-group data-minor="{{ in_array($key, ['under_12', '12_17'], true) ? 1 : 0 }}">
                                    </div>
                                @endforeach
                            </div>
                            <p class="field__error" data-age-error @if (! $errors->has('age_groups')) hidden @endif>{{ $errors->first('age_groups') ?: __('site.booking.age_groups_mismatch') }}</p>
                        </div>
                    @endif
                </fieldset>

                <fieldset class="booking-step" data-step="time" style="border:0;padding-inline:0;margin:0">
                    <legend><h2>{{ __('site.booking.step_time') }}</h2></legend>
                    <div data-instant-fields @if ($selected && ! $selected->supportsInstantBooking()) hidden @endif>
                        <div class="calendar mt-5" data-calendar>
                            <p class="muted">{{ __('site.booking.choose_experience') }}</p>
                        </div>
                        <div data-slots aria-live="polite"></div>
                        <p class="hold-timer" data-slot-status role="status" aria-live="polite"></p>
                        @error('starts_at')<p class="field__error">{{ $message }}</p>@enderror

                        {{-- Variantă fără JavaScript: dată + oră, verificate integral pe server --}}
                        <div class="field-row mt-5" data-nojs-time>
                            @include('site.partials.field', ['name' => 'date', 'type' => 'date', 'label' => __('site.booking.choose_date'), 'min' => now()->toDateString(), 'id' => 'nojs-date'])
                            @include('site.partials.field', ['name' => 'time', 'type' => 'time', 'label' => __('site.booking.choose_time'), 'id' => 'nojs-time'])
                        </div>
                    </div>
                    <div data-request-fields @if (! $selected || $selected->supportsInstantBooking()) hidden @endif>
                        <p class="alert">{{ __('site.booking.request_mode') }}</p>
                        <div class="field-row mt-5">
                            @include('site.partials.field', ['name' => 'preferred_date', 'type' => 'date', 'label' => __('site.booking.preferred_date'), 'required' => true, 'min' => now()->toDateString(), 'id' => 'pref-date'])
                            @include('site.partials.field', ['name' => 'preferred_time', 'type' => 'select', 'label' => __('site.booking.preferred_time'), 'options' => __('site.booking.preferred_time_options'), 'id' => 'pref-time'])
                        </div>
                    </div>
                </fieldset>

                <fieldset class="booking-step" style="border:0;padding-inline:0;margin:0">
                    <legend><h2>{{ __('site.booking.extras') }}</h2></legend>
                    <div class="mt-5" data-extras>
                        @if ($selected && $selected->addons->where('is_active', true)->isNotEmpty())
                            @foreach ($selected->addons->where('is_active', true) as $addon)
                                <label class="check"><input type="checkbox" name="addons[{{ $addon->id }}]" value="1" @checked(old('addons.'.$addon->id))><span><strong>{{ $addon->name }}</strong>@if ($addon->price_minor !== null) — {{ money($addon->price_minor) }}@endif</span></label>
                            @endforeach
                        @else
                            <p class="muted">{{ __('site.booking.no_extras') }}</p>
                        @endif
                    </div>
                </fieldset>

                <fieldset class="booking-step" style="border:0;padding-inline:0;margin:0">
                    <legend><h2>{{ __('site.booking.contact_title') }}</h2></legend>
                    <p class="field__hint">{{ __('site.booking.contact_hint') }}</p>
                    <div class="field-row mt-5">
                        @include('site.partials.field', ['name' => 'contact_name', 'label' => __('site.booking.name'), 'required' => true, 'autocomplete' => 'name'])
                        @include('site.partials.field', ['name' => 'company_name', 'label' => __('site.booking.company'), 'autocomplete' => 'organization'])
                    </div>
                    <div class="field-row mt-5">
                        @include('site.partials.field', ['name' => 'contact_email', 'type' => 'email', 'label' => __('site.booking.email'), 'required' => true, 'autocomplete' => 'email'])
                        @include('site.partials.field', ['name' => 'contact_phone', 'type' => 'tel', 'label' => __('site.booking.phone'), 'required' => true, 'autocomplete' => 'tel', 'inputmode' => 'tel'])
                    </div>
                    <div class="mt-5">
                        @include('site.partials.field', ['name' => 'notes', 'type' => 'textarea', 'label' => __('site.booking.notes'), 'hint' => __('site.booking.notes_hint'), 'max' => 1000])
                    </div>
                    @if (count($paymentMethods) > 1)
                        <div class="field mt-5">
                            <p class="field__label">{{ __('site.booking.payment_method') }}</p>
                            <div class="choice-grid">
                                @foreach ($paymentMethods as $method => $label)
                                    <label class="choice"><input type="radio" name="payment_method" value="{{ $method }}" @checked(old('payment_method', array_key_first($paymentMethods)) === $method)><span class="choice__title">{{ $label }}</span></label>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <input type="hidden" name="payment_method" value="{{ array_key_first($paymentMethods) }}">
                    @endif
                </fieldset>

                <fieldset class="booking-step" style="border:0;padding-inline:0;margin:0">
                    <legend><h2>{{ __('site.booking.summary_conditions') }}</h2></legend>
                    <label class="check mt-5">
                        <input type="checkbox" name="accept_terms" value="1" required @checked(old('accept_terms')) @error('accept_terms') aria-invalid="true" @enderror>
                        <span>{!! __('site.booking.accept_terms', [
                            'terms' => isset($policies['terms']) ? '<a href="'.e($policies['terms']).'" target="_blank">'.e(__('site.booking.terms_link')).'</a>' : e(__('site.booking.terms_link')),
                            'policy' => isset($policies['booking_policy']) ? '<a href="'.e($policies['booking_policy']).'" target="_blank">'.e(__('site.booking.policy_link')).'</a>' : e(__('site.booking.policy_link')),
                        ]) !!}</span>
                    </label>
                    @error('accept_terms')<p class="field__error">{{ $message }}</p>@enderror
                    <label class="check" data-minors-check hidden>
                        <input type="checkbox" name="accept_minors" value="1" @checked(old('accept_minors'))>
                        <span>{!! __('site.booking.accept_minors', ['minors' => isset($policies['minors']) ? '<a href="'.e($policies['minors']).'" target="_blank">'.e(__('site.booking.minors_link')).'</a>' : e(__('site.booking.minors_link'))]) !!}</span>
                    </label>
                    @error('accept_minors')<p class="field__error">{{ $message }}</p>@enderror
                    @include('site.partials.privacy-consents', ['prefix' => 'booking'])

                    <button type="submit" class="btn btn--primary mt-6" data-busy="{{ __('site.booking.submitting') }}">
                        {{ $selected && ! $selected->supportsInstantBooking() ? __('site.booking.submit_request') : __('site.booking.submit_instant') }}
                    </button>
                </fieldset>
            </form>

            <aside class="booking-summary panel" data-summary aria-live="polite">
                <h2 style="font-size:var(--fs-md)">{{ __('site.booking.summary_title') }}</h2>
                <p class="muted small mt-4">{{ __('site.booking.choose_experience') }}</p>
            </aside>
        </div>
        @endif
    </div>
</section>
@endsection
