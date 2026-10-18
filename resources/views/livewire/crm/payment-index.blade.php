@php use App\Support\Money; @endphp
<div class="max-w-6xl">
    <div class="flex flex-wrap items-end gap-4">
        <div>
            <label for="from" class="field-label">{{ __('finance.from') }}</label>
            <input id="from" type="date" wire:model.live="from" class="input">
        </div>
        <div>
            <label for="to" class="field-label">{{ __('finance.to') }}</label>
            <input id="to" type="date" wire:model.live="to" class="input">
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
        <div>
            <label for="method" class="field-label">{{ __('finance.method') }}</label>
            <select id="method" wire:model.live="method" class="input">
                <option value="">{{ __('crm.all') }}</option>
                @foreach (\App\Models\Payment::METHODS as $m)<option value="{{ $m }}">{{ __('finance.methods.'.$m) }}</option>@endforeach
            </select>
        </div>
    </div>

    <dl class="mt-6 grid gap-3 sm:grid-cols-3">
        <div class="panel p-4"><dt class="text-xs text-muted">{{ __('finance.net') }}</dt><dd class="mt-1 text-xl font-semibold tabular-nums">{{ Money::format($net) }}</dd>
            <dd class="mt-2 space-y-0.5 text-xs text-muted">@foreach ($byMethod as $m => $v)<span class="block">{{ __('finance.methods.'.$m) }}: <span class="tabular-nums">{{ Money::format($v) }}</span></span>@endforeach</dd></div>
        <div class="panel p-4"><dt class="text-xs text-muted">{{ __('finance.services_value') }}</dt><dd class="mt-1 text-xl font-semibold tabular-nums">{{ Money::format($servicesValue) }}</dd></div>
        <div class="panel p-4"><dt class="text-xs text-muted">{{ __('finance.orders_value') }}</dt><dd class="mt-1 text-xl font-semibold tabular-nums">{{ Money::format($ordersValue) }}</dd></div>
    </dl>

    <div class="panel mt-6 overflow-x-auto">
        <table class="table-base table-stack">
            <thead><tr>
                <th scope="col">{{ __('crm.fields.time') }}</th>
                <th scope="col">{{ __('crm.fields.patient') }}</th>
                <th scope="col">{{ __('finance.for') }}</th>
                <th scope="col">{{ __('finance.method') }}</th>
                <th scope="col">{{ __('finance.operator') }}</th>
                <th scope="col" class="text-right">{{ __('finance.amount') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($rows as $p)
                <tr wire:key="pm-{{ $p->id }}" @class(['opacity-60' => $p->voided_at])>
                    <td class="whitespace-nowrap">{{ $p->received_at->timezone($tz)->format('d.m.Y H:i') }}<span class="block text-xs text-muted">{{ $p->location?->name() }}</span></td>
                    <td data-label="{{ __('crm.fields.patient') }}"><a href="{{ route('crm.patients.show', ['patient' => $p->patient_id, 'tab' => 'finance']) }}" wire:navigate class="btn-link">{{ $p->patient?->fullName() }}</a></td>
                    <td data-label="{{ __('finance.for') }}">
                        @if ($p->payable_type === 'order')
                            <a href="{{ route('crm.orders.edit', $p->payable_id) }}" wire:navigate class="btn-link">{{ __('finance.order_ref', ['number' => $p->payable?->number]) }}</a>
                        @else
                            {{ __('finance.charge_ref', ['name' => $p->payable?->description]) }}
                        @endif
                        <span class="block text-xs text-muted">{{ __('finance.types.'.$p->type) }}@if ($p->is_advance) · {{ __('finance.advance') }}@endif</span>
                        @if ($p->voided_at)<span class="block text-xs text-bad">{{ __('finance.voided', ['reason' => $p->void_reason]) }}</span>@endif
                    </td>
                    <td data-label="{{ __('finance.method') }}">{{ __('finance.methods.'.$p->method) }}</td>
                    <td data-label="{{ __('finance.operator') }}">{{ $p->operator?->name }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums" data-label="{{ __('finance.amount') }}"><span @class(['line-through' => $p->voided_at])>{{ $p->type === 'refund' ? '−' : '' }}{{ Money::format($p->amount_cents) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-10 text-center text-muted">{{ __('finance.none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $rows->links() }}</div>
    <p class="mt-4 text-xs text-muted">{{ __('finance.not_fiscal') }}</p>
</div>
