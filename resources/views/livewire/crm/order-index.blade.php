@php use App\Support\Money; @endphp
<div class="max-w-6xl">
    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full max-w-xs">
            <label for="search" class="field-label">{{ __('crm.actions.search') }}</label>
            <input id="search" type="search" wire:model.live.debounce.300ms="search" class="input" placeholder="{{ __('orders.number') }} / {{ __('orders.patient') }}">
        </div>
        <div>
            <label for="status" class="field-label">{{ __('orders.status') }}</label>
            <select id="status" wire:model.live="status" class="input">
                <option value="open">{{ __('orders.open_only') }}</option>
                @foreach (\App\Models\Order::STATUSES as $s)<option value="{{ $s }}">{{ __('orders.statuses.'.$s) }}</option>@endforeach
                <option value="all">{{ __('crm.all') }}</option>
            </select>
        </div>
        @if ($locations->count() > 1)
            <div>
                <label for="loc" class="field-label">{{ __('crm.fields.location') }}</label>
                <select id="loc" wire:model.live="location" class="input">
                    <option value="">{{ __('crm.all') }}</option>
                    @foreach ($locations as $l)<option value="{{ $l->id }}">{{ $l->name() }}</option>@endforeach
                </select>
            </div>
        @endif
        <label class="flex min-h-11 items-center gap-2 text-sm">
            <input type="checkbox" wire:model.live="delayed" class="h-5 w-5 rounded border-line text-ink focus:ring-ink">
            {{ __('orders.only_delayed') }} @if ($delayedCount)<span class="badge-bad">{{ $delayedCount }}</span>@endif
        </label>
        @can('orders.manage')
            <a href="{{ route('crm.orders.create') }}" wire:navigate class="btn-primary ml-auto">{{ __('orders.create') }}</a>
        @endcan
    </div>

    <div class="panel mt-6 overflow-x-auto">
        <table class="table-base table-stack">
            <thead><tr>
                <th scope="col">{{ __('orders.number') }}</th>
                <th scope="col">{{ __('orders.patient') }}</th>
                <th scope="col">{{ __('orders.status') }}</th>
                <th scope="col">{{ __('orders.due_on') }}</th>
                <th scope="col" class="text-right">{{ __('orders.total') }}</th>
                @can('payments.view')<th scope="col" class="text-right">{{ __('orders.balance') }}</th>@endcan
            </tr></thead>
            <tbody>
            @forelse ($orders as $o)
                <tr wire:key="o-{{ $o->id }}">
                    <td><a href="{{ route('crm.orders.edit', $o) }}" wire:navigate class="font-medium text-heading hover:underline">{{ $o->number }}</a>
                        <span class="block text-xs text-muted">{{ $o->location?->name() }}</span></td>
                    <td data-label="{{ __('orders.patient') }}">{{ $o->patient?->fullName() }}</td>
                    <td data-label="{{ __('orders.status') }}">
                        <span class="{{ match ($o->status) { 'ready' => 'badge-ok', 'delivered', 'cancelled' => 'badge-neutral', 'new' => 'badge-warn', default => 'badge-info' } }}">{{ __('orders.statuses.'.$o->status) }}</span>
                        @if ($o->isDelayed())<span class="badge-bad">{{ __('orders.delayed') }}</span>@endif
                    </td>
                    <td class="whitespace-nowrap" data-label="{{ __('orders.due_on') }}">{{ $o->due_on?->format('d.m.Y') ?? '—' }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums" data-label="{{ __('orders.total') }}">{{ Money::format($o->total_cents) }}</td>
                    @can('payments.view')
                        <td class="whitespace-nowrap text-right tabular-nums" data-label="{{ __('orders.balance') }}">{{ Money::format($balances[$o->id] ?? null) }}</td>
                    @endcan
                </tr>
            @empty
                <tr><td colspan="6" class="py-10 text-center text-muted">{{ __('orders.none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $orders->links() }}</div>
</div>
