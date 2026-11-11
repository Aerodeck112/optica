@extends('site.layout')

@section('content')
<section class="tablet" data-tablet-keypad>
    <img class="tablet__logo" src="{{ asset('media/brand/aw-logo-stacked-cluj.svg') }}" alt="Another World Cluj" width="180" height="120">

    @if (session('waiver_signed'))
        <div class="tablet__done" role="status" data-tablet-done>
            <x-aw-icon name="check"/>
            <p class="tablet__done-title">{{ __('site.waiver.tablet.done', ['name' => \Illuminate\Support\Str::of(session('waiver_signed'))->before(' ')]) }}</p>
            <p>{{ __('site.waiver.tablet.handover') }}</p>
        </div>
    @endif

    <h1 class="tablet__title">{{ __('site.waiver.tablet.title') }}</h1>
    <p class="tablet__lede">{{ __('site.waiver.tablet.lede') }}</p>

    <form class="tablet__form" method="post" action="{{ lroute('waiver.tablet.enter') }}" data-tablet-form novalidate>
        @csrf
        <label class="sr-only" for="tablet-code">{{ __('site.waiver.tablet.label') }}</label>
        <input id="tablet-code" class="tablet__code @error('code') is-invalid @enderror" name="code" type="text" inputmode="none" autocomplete="off" maxlength="7"
               pattern="[0-9 ]*" placeholder="••• •••" aria-describedby="tablet-code-error" data-tablet-code>
        <p class="tablet__error" id="tablet-code-error" role="alert">@error('code'){{ $message }}@enderror</p>

        <div class="tablet__keys" role="group" aria-label="{{ __('site.waiver.tablet.label') }}">
            @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $digit)
                <button type="button" class="tablet__key" data-key="{{ $digit }}">{{ $digit }}</button>
            @endforeach
            <button type="button" class="tablet__key tablet__key--muted" data-key="clear">{{ __('site.waiver.tablet.clear') }}</button>
            <button type="button" class="tablet__key" data-key="0">0</button>
            <button type="button" class="tablet__key tablet__key--muted" data-key="back" aria-label="{{ __('site.waiver.tablet.back') }}">⌫</button>
        </div>
        <button type="submit" class="btn btn--primary btn--block tablet__submit" data-tablet-submit disabled>{{ __('site.waiver.tablet.submit') }}</button>
        <button type="button" class="btn btn--ghost btn--block" data-tablet-scan data-stop="{{ __('site.waiver.tablet.scan_stop') }}" hidden>{{ __('site.waiver.tablet.scan') }}</button>
        <video class="tablet__video" data-tablet-video playsinline muted hidden></video>
    </form>

    <p class="tablet__note">{{ __('site.waiver.tablet.minor') }}</p>

    @if (count(\App\Support\Locales::enabled()) > 1)
        <nav class="tablet__langs" aria-label="{{ __('site.waiver.tablet.language') }}">
            @foreach (\App\Support\Locales::enabled() as $lang)
                <a href="{{ lroute('waiver.tablet', [], $lang) }}" @if ($lang === app()->getLocale()) aria-current="true" @endif lang="{{ $lang }}">{{ strtoupper($lang) }}</a>
            @endforeach
        </nav>
    @endif
</section>
@endsection
