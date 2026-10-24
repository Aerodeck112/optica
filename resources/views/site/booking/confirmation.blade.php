@extends('site.layout')

@php
    $status = $booking->status;
    $titleKey = match ($status) { 'confirmed' => 'confirmed_title', 'request' => 'request_title', default => 'pending_title' };
    $textKey = match (true) {
        $status === 'pending' && $paymentReturned => 'payment_processing_text',
        $status === 'confirmed' => 'confirmed_text',
        $status === 'request' => 'request_text',
        $booking->expires_at !== null && $booking->deposit_minor > 0 => 'pending_payment_text',
        default => 'pending_text',
    };
    $event = match ($status) { 'confirmed' => 'booking_confirmed', 'request' => 'generate_lead', default => 'booking_pending' };
@endphp

@section('content')
<section class="section">
    <div class="wrap result" @if ($justCreated) data-track-view="{{ $event }}" data-track-params='{!! aw_json(['item_category' => $booking->package?->type, 'booking_ref' => substr(hash('sha256', $booking->code), 0, 12)]) !!}' @endif>
        <div class="result__icon"><x-aw-icon name="check"/></div>
        <h1>{{ __('site.booking.'.$titleKey) }}</h1>
        <p class="hero__lede" style="margin-inline:auto">{{ __('site.booking.'.$textKey, ['until' => $booking->expires_at ? \App\Support\LocalTime::format($booking->expires_at, 'j F, H:i') : '']) }}</p>
        <p class="result__code" aria-label="{{ __('site.booking.code') }}">{{ $booking->code }}</p>
        @if (session('payment_error'))<div class="alert alert--error" role="alert">{{ session('payment_error') }} {{ __('site.booking.payment_retry_hint') }}</div>@endif

        <dl class="details-list">
            <dt>{{ __('site.booking.summary_experience') }}</dt><dd>{{ $booking->package?->name }}</dd>
            @if ($booking->starts_at)
                <dt>{{ $status === 'request' ? __('site.booking.preferred_date') : __('site.booking.summary_when') }}</dt>
                <dd>{{ $status === 'request' ? \App\Support\LocalTime::format($booking->starts_at, 'l, j F Y') : $booking->localStart() }}</dd>
            @endif
            <dt>{{ __('site.booking.summary_participants') }}</dt><dd>{{ $booking->participants }}</dd>
            <dt>{{ __('site.booking.summary_total') }}</dt><dd>{{ $booking->total_minor !== null ? money($booking->total_minor) : __('site.booking.summary_price_tbd') }}</dd>
            @if ($booking->deposit_minor > 0)<dt>{{ __('site.booking.summary_deposit') }}</dt><dd>{{ money($booking->deposit_minor) }}</dd>@endif
            <dt>{{ __('site.booking.status') }}</dt><dd>{{ $booking->statusLabel() }}</dd>
        </dl>

        <div class="cluster mt-7" style="justify-content:center">
            @if ($booking->starts_at && $status !== 'request')
                <a class="btn btn--ghost" href="{{ route('booking.ics', ['code' => $booking->code]) }}?t={{ $token }}"><x-aw-icon name="calendar" style="width:1.1rem;height:1.1rem"/>{{ __('site.booking.add_to_calendar') }}</a>
            @endif
            <a class="btn btn--primary" href="{{ lroute('booking.manage', ['token' => $token]) }}">{{ __('site.booking.manage') }}</a>
        </div>
    </div>
</section>
@endsection
