@extends('site.layout')

@php
    $issued = $order->status === 'issued' && $order->voucher;
    $failed = in_array($order->payment?->status, ['failed', 'cancelled'], true) && ! $issued;
    if (! $issued && ! $failed) {
        app('view')->startPush('preload', '<meta http-equiv="refresh" content="6">');
    }
@endphp

@section('content')
<section class="section">
    <div class="wrap result">
        <div class="result__icon"><x-aw-icon name="{{ $issued ? 'check' : 'info' }}"/></div>
        @if ($issued)
            <h1>{{ __('site.voucher_order.ready_title') }}</h1>
            <p class="hero__lede" style="margin-inline:auto">{{ __('site.voucher_order.ready_text', ['email' => $order->buyer_email]) }}</p>
            <p class="result__code" aria-label="{{ __('site.voucher_order.code') }}">{{ $order->voucher->code }}</p>
            <dl class="details-list">
                <dt>{{ __('site.voucher_order.voucher') }}</dt><dd>{{ $label }}</dd>
                @if ($order->recipient_name)<dt>{{ __('site.voucher_order.for') }}</dt><dd>{{ $order->recipient_name }}</dd>@endif
                <dt>{{ __('site.voucher_order.valid_until') }}</dt><dd>{{ $order->voucher->valid_until?->format('d.m.Y') }}</dd>
                <dt>{{ __('site.voucher_order.paid') }}</dt><dd>{{ money($order->amount_minor) }}</dd>
            </dl>
            <p class="mt-6"><a class="btn btn--primary" href="{{ $downloadUrl }}" target="_blank" rel="noopener">{{ __('site.voucher_order.download') }}</a></p>
        @elseif ($failed)
            <h1>{{ __('site.voucher_order.failed_title') }}</h1>
            <p class="hero__lede" style="margin-inline:auto">{{ __('site.voucher_order.failed_text') }}</p>
            <p class="mt-6"><a class="btn btn--primary" href="{{ lroute('voucher.order.create') }}?tip={{ $order->product }}">{{ __('site.voucher_order.retry') }}</a></p>
        @else
            <h1>{{ __('site.voucher_order.pending_title') }}</h1>
            <p class="hero__lede" style="margin-inline:auto">{{ __('site.voucher_order.pending_text') }}</p>
        @endif
    </div>
</section>
@endsection
