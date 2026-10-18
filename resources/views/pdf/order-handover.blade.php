@php use App\Support\Money; @endphp
<x-pdf-layout :locale="$locale" :logo="$logo" :tz="$tz" :location="$order->location" :title="__('orders.handover_title', ['number' => $order->number])">
    <h1>{{ __('orders.handover_title', ['number' => $order->number]) }}</h1>
    <table class="meta">
        <tr><td class="muted">{{ __('orders.patient') }}</td><td><strong>{{ $order->patient->fullName() }}</strong> ({{ $order->patient->code }})</td></tr>
        <tr><td class="muted">{{ __('orders.status') }}</td><td>{{ __('orders.statuses.'.$order->status) }}@if ($order->delivered_at) · {{ $order->delivered_at->timezone($tz)->format('d.m.Y H:i') }}@endif</td></tr>
        @if ($order->due_on)<tr><td class="muted">{{ __('orders.due_on') }}</td><td>{{ $order->due_on->format('d.m.Y') }}</td></tr>@endif
    </table>
    <h2>{{ __('orders.items') }}</h2>
    <table class="data">
        <tr><th>{{ __('orders.item_kind') }}</th><th>{{ __('orders.item_description') }}</th><th>{{ __('orders.quantity') }}</th><th>{{ __('orders.unit_price') }}</th><th>{{ __('orders.line_total') }}</th></tr>
        @foreach ($order->items as $item)
            <tr><td>{{ __('orders.kinds.'.$item->kind) }}</td><td>{{ $item->description }}</td><td>{{ $item->quantity }}</td><td style="text-align:right">{{ Money::format($item->unit_price_cents, false) }}</td><td style="text-align:right">{{ Money::format($item->totalCents(), false) }}</td></tr>
        @endforeach
        <tr><th colspan="4" style="text-align:right">{{ __('orders.total') }}</th><th style="text-align:right">{{ Money::format($summary['value']) }}</th></tr>
        <tr><td colspan="4" style="text-align:right">{{ __('orders.paid') }}</td><td style="text-align:right">{{ Money::format($summary['paid']) }}</td></tr>
        <tr><td colspan="4" style="text-align:right">{{ __('orders.balance') }}</td><td style="text-align:right">{{ Money::format($summary['balance']) }}</td></tr>
    </table>
    <table style="width:100%; margin-top: 40px">
        <tr>
            <td style="width:50%">{{ __('orders.delivered_by') }}: {{ $order->responsible?->name }}<br><br>{{ __('orders.signature') }}: ____________________</td>
            <td>{{ __('orders.received_by') }}<br><br>{{ __('orders.signature') }}: ____________________</td>
        </tr>
    </table>
    <p class="notice">{{ __('finance.not_fiscal') }}</p>
</x-pdf-layout>
