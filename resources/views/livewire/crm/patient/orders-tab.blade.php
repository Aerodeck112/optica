@php use App\Support\Money; @endphp
<div class="space-y-4">
    @can('orders.manage')
        <a href="{{ route('crm.orders.create', ['patient' => $patientId]) }}" wire:navigate class="btn-primary">{{ __('orders.create') }}</a>
    @endcan
    <div class="panel overflow-x-auto">
        <table class="table-base table-stack">
            <thead><tr>
                <th scope="col">{{ __('orders.number') }}</th>
                <th scope="col">{{ __('orders.status') }}</th>
                <th scope="col">{{ __('orders.due_on') }}</th>
                <th scope="col" class="text-right">{{ __('orders.total') }}</th>
                @can('payments.view')<th scope="col" class="text-right">{{ __('orders.balance') }}</th>@endcan
            </tr></thead>
            <tbody>
            @forelse ($orders as $o)
                <tr wire:key="po-{{ $o->id }}">
                    <td><a href="{{ route('crm.orders.edit', $o) }}" wire:navigate class="font-medium text-heading hover:underline">{{ $o->number }}</a><span class="block text-xs text-muted">{{ $o->location?->name() }}</span></td>
                    <td data-label="{{ __('orders.status') }}">{{ __('orders.statuses.'.$o->status) }}@if ($o->isDelayed()) <span class="badge-bad">{{ __('orders.delayed') }}</span>@endif</td>
                    <td data-label="{{ __('orders.due_on') }}">{{ $o->due_on?->format('d.m.Y') ?? '—' }}</td>
                    <td class="text-right tabular-nums" data-label="{{ __('orders.total') }}">{{ Money::format($o->total_cents) }}</td>
                    @can('payments.view')<td class="text-right tabular-nums" data-label="{{ __('orders.balance') }}">{{ Money::format($balances[$o->id] ?? null) }}</td>@endcan
                </tr>
            @empty
                <tr><td colspan="5" class="py-8 text-center text-muted">{{ __('orders.none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
