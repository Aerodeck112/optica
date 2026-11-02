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
            <h1>Voucherul tău este gata</h1>
            <p class="hero__lede" style="margin-inline:auto">Ți-am trimis voucherul și pe e-mail, la {{ $order->buyer_email }}. Îl poți descărca sau printa de aici oricând.</p>
            <p class="result__code" aria-label="Codul voucherului">{{ $order->voucher->code }}</p>
            <dl class="details-list">
                <dt>Voucher</dt><dd>{{ $label }}</dd>
                @if ($order->recipient_name)<dt>Pentru</dt><dd>{{ $order->recipient_name }}</dd>@endif
                <dt>Valabil până la</dt><dd>{{ $order->voucher->valid_until?->format('d.m.Y') }}</dd>
                <dt>Achitat</dt><dd>{{ money($order->amount_minor) }}</dd>
            </dl>
            <p class="mt-6"><a class="btn btn--primary" href="{{ $downloadUrl }}" target="_blank" rel="noopener">Descarcă / printează voucherul</a></p>
        @elseif ($failed)
            <h1>Plata nu a fost finalizată</h1>
            <p class="hero__lede" style="margin-inline:auto">Nu s-a încasat nimic. Poți încerca din nou.</p>
            <p class="mt-6"><a class="btn btn--primary" href="{{ route('voucher.order.create', ['tip' => $order->product]) }}">Încearcă din nou</a></p>
        @else
            <h1>Confirmăm plata…</h1>
            <p class="hero__lede" style="margin-inline:auto">Durează de obicei câteva secunde. Pagina se actualizează singură, iar voucherul îți vine și pe e-mail.</p>
        @endif
    </div>
</section>
@endsection
