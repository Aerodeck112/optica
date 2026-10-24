@extends('site.layout')

@section('content')
<section class="section">
    <div class="wrap--narrow stack" style="--stack: var(--space-6)">
        <div>
            <h1>{{ __('site.booking.manage_title') }}</h1>
            <p class="result__code">{{ $booking->code }}</p>
        </div>

        @if (in_array($booking->status, ['confirmed', 'pending'], true) && $booking->starts_at)
            <div class="panel checkin-qr">
                <div class="checkin-qr__code" role="img" aria-label="{{ __('site.booking.checkin_qr_alt', ['code' => $booking->code]) }}">{!! \App\Modules\Booking\Services\CheckinCode::svg($booking) !!}</div>
                <p>{{ __('site.booking.checkin_qr_help') }}</p>
            </div>
        @endif

        @if (session('status'))<div class="alert alert--ok" role="status">{{ session('status') }}</div>@endif
        @if ($errors->any())<div class="alert alert--error" role="alert">{{ $errors->first() }}</div>@endif

        <div class="panel">
            <dl class="details-list" style="margin-top:0">
                <dt>{{ __('site.booking.summary_experience') }}</dt><dd>{{ $booking->package?->name }}</dd>
                @if ($booking->starts_at)<dt>{{ __('site.booking.summary_when') }}</dt><dd>{{ $booking->localStart() }}</dd>@endif
                <dt>{{ __('site.booking.summary_participants') }}</dt><dd>{{ $booking->participants }}</dd>
                <dt>{{ __('site.booking.status') }}</dt><dd>{{ $booking->statusLabel() }}</dd>
                <dt>{{ __('site.booking.payment_status') }}</dt><dd>{{ $booking->paymentStatusLabel() }}</dd>
                <dt>{{ __('site.booking.summary_total') }}</dt><dd>{{ $booking->total_minor !== null ? money($booking->total_minor) : __('site.booking.summary_price_tbd') }}</dd>
            </dl>
            <div class="cluster mt-6">
                @if ($booking->starts_at && $booking->occupiesArena())
                    <a class="btn btn--ghost" href="{{ route('booking.ics', ['code' => $booking->code]) }}?t={{ $token }}">{{ __('site.booking.add_to_calendar') }}</a>
                @endif
                @if ($payment)
                    <a class="btn btn--primary" href="{{ $payment->checkout_url }}" rel="noopener">{{ __('site.booking.pay_deposit') }}</a>
                @endif
            </div>
        </div>

        @if ($canReschedule)
            <section class="panel" data-reschedule>
                <h2 style="font-size:var(--fs-lg)">{{ __('site.booking.reschedule') }}</h2>
                <script type="application/json" data-reschedule-config nonce="{{ csp_nonce() }}">{!! aw_json([
                    'package_id' => $booking->package_id,
                    'participants' => $booking->participants,
                    'booking_ref' => $excludeRef,
                    'endpoints' => ['slots' => route('api.booking.slots')],
                    'i18n' => ['loading' => __('site.loading'), 'no_slots' => __('site.booking.no_slots'), 'error_generic' => __('site.booking.error_generic')],
                ]) !!}</script>
                <form method="post" action="{{ route('booking.manage.reschedule', ['token' => $token]) }}" class="mt-5">
                    @csrf
                    <input type="hidden" name="starts_at" value="">
                    @include('site.partials.field', ['name' => 'date', 'type' => 'date', 'label' => __('site.booking.choose_date'), 'min' => now()->toDateString(), 'id' => 'resched-date'])
                    <div data-slots aria-live="polite" class="mt-4"></div>
                    <button type="submit" class="btn btn--primary mt-5">{{ __('site.booking.reschedule') }}</button>
                </form>
            </section>
        @endif

        @if ($canCancel)
            <section class="panel">
                <h2 style="font-size:var(--fs-lg)">{{ __('site.booking.cancel') }}</h2>
                <form method="post" action="{{ route('booking.manage.cancel', ['token' => $token]) }}" class="mt-5" data-confirm="{{ __('site.booking.cancel_confirm') }}">
                    @csrf
                    @include('site.partials.field', ['name' => 'reason', 'label' => __('site.booking.cancel_reason'), 'id' => 'cancel-reason'])
                    <button type="submit" class="btn btn--ghost mt-5">{{ __('site.booking.cancel') }}</button>
                </form>
            </section>
        @elseif ($booking->isOpen())
            <p class="muted small">{{ __('site.booking.cancel_not_allowed') }}</p>
        @endif
    </div>
</section>
@endsection
