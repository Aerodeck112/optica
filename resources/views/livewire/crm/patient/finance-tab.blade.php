@php use App\Support\Money; @endphp
<div class="space-y-6">
    <dl class="grid gap-3 sm:grid-cols-3">
        <div class="panel p-4"><dt class="text-xs text-muted">{{ __('finance.value') }}</dt><dd class="mt-1 text-lg font-semibold tabular-nums">{{ Money::format($totals['value']) }}</dd></div>
        <div class="panel p-4"><dt class="text-xs text-muted">{{ __('finance.paid') }}</dt><dd class="mt-1 text-lg font-semibold tabular-nums">{{ Money::format($totals['paid']) }}</dd></div>
        <div class="panel p-4"><dt class="text-xs text-muted">{{ $totals['balance'] < 0 ? __('orders.credit') : __('finance.balance') }}</dt><dd @class(['mt-1 text-lg font-semibold tabular-nums', 'text-warn' => $totals['balance'] > 0, 'text-bad' => $totals['balance'] < 0])>{{ Money::format(abs($totals['balance'])) }}</dd></div>
    </dl>

    <section aria-labelledby="ch-title">
        <div class="flex flex-wrap items-center gap-3">
            <h2 id="ch-title" class="flex-1 text-base font-semibold">{{ __('finance.charges') }}</h2>
            @can('payments.create')
                @unless ($adding)<button type="button" class="btn-primary" wire:click="startAdd">{{ __('finance.add_charge') }}</button>@endunless
            @endcan
        </div>

        @if ($adding)
            <form wire:submit="add" class="panel mt-3 space-y-4 p-5">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="ch-appt" class="field-label">{{ __('finance.charge_appointment') }}</label>
                        <select id="ch-appt" wire:model.live="appointmentId" class="input">
                            <option value="">—</option>
                            @foreach ($appointments as $a)<option value="{{ $a->id }}">{{ $a->starts_at->timezone($tz)->format('d.m.Y H:i') }} · {{ $a->service?->name() }}</option>@endforeach
                        </select>
                    </div>
                    @if (! $appointmentId)
                        <div>
                            <label for="ch-loc" class="field-label">{{ __('crm.fields.location') }}</label>
                            <select id="ch-loc" wire:model="locationId" class="input">
                                @foreach ($locations as $l)<option value="{{ $l->id }}">{{ $l->name() }}</option>@endforeach
                            </select>
                        </div>
                    @endif
                    <x-admin.text model="description" :label="__('finance.charge_description')" required maxlength="300" />
                    <div class="grid grid-cols-2 gap-4">
                        <x-admin.text model="amount" inputmode="decimal" :label="__('finance.amount')" required />
                        <x-admin.text model="performedOn" type="date" :label="__('finance.performed_on')" required />
                    </div>
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="btn-primary">{{ __('crm.actions.save') }}</button>
                    <button type="button" class="btn-secondary" wire:click="$set('adding', false)">{{ __('crm.actions.cancel') }}</button>
                </div>
            </form>
        @endif

        <ul class="mt-3 space-y-3" role="list">
            @forelse ($rows as $row)
                @php($c = $row['charge'])
                <li class="panel p-4" wire:key="ch-{{ $c->id }}">
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" class="min-w-0 flex-1 text-left" wire:click="select({{ $c->id }})" aria-expanded="{{ $selected === $c->id ? 'true' : 'false' }}">
                            <span @class(['block font-medium', 'text-muted line-through' => $c->voided_at, 'text-heading' => ! $c->voided_at])>{{ $c->description }}</span>
                            <span class="block text-xs text-muted">{{ $c->performed_on->format('d.m.Y') }} · {{ $c->location?->name() }}@if ($c->specialist) · {{ $c->specialist->display_name }}@endif</span>
                        </button>
                        <span class="tabular-nums">{{ Money::format($row['value']) }}</span>
                        <span @class(['badge-ok' => $row['balance'] === 0, 'badge-warn' => $row['balance'] > 0, 'badge-bad' => $row['balance'] < 0])>
                            {{ $row['balance'] === 0 ? __('finance.paid') : ($row['balance'] > 0 ? __('finance.balance').' '.Money::format($row['balance']) : __('orders.credit').' '.Money::format(-$row['balance'])) }}</span>
                    </div>
                    @if ($c->voided_at)<p class="mt-1 text-xs text-bad">{{ __('finance.voided', ['reason' => $c->void_reason]) }}</p>@endif
                    @if ($selected === $c->id)
                        <div class="mt-4">
                            <livewire:crm.payments-panel payable-type="service_charge" :payable-id="$c->id" :key="'pay-charge-'.$c->id" />
                            @can('payments.refund')
                                @if (! $c->voided_at)
                                    @if ($voiding === $c->id)
                                        <form wire:submit="void" class="mt-3 flex flex-wrap items-end gap-2">
                                            <div class="min-w-48 flex-1"><x-admin.text model="voidReason" :label="__('finance.void_reason')" required /></div>
                                            <button type="submit" class="btn-danger" wire:confirm="{{ __('finance.confirm_void') }}">{{ __('finance.void_charge') }}</button>
                                            <button type="button" class="btn-secondary" wire:click="cancelVoid">{{ __('crm.actions.cancel') }}</button>
                                        </form>
                                    @else
                                        <button type="button" class="btn-link mt-3 text-sm text-muted" wire:click="startVoid({{ $c->id }})">{{ __('finance.void_charge') }}</button>
                                    @endif
                                @endif
                            @endcan
                        </div>
                    @endif
                </li>
            @empty
                <li class="panel p-6 text-center text-sm text-muted">{{ __('finance.none') }}</li>
            @endforelse
        </ul>
    </section>

    @if ($orders->isNotEmpty())
        <section aria-labelledby="fo-title">
            <h2 id="fo-title" class="text-base font-semibold">{{ __('orders.title') }}</h2>
            <ul class="panel mt-3 divide-y divide-line" role="list">
                @foreach ($orders as $row)
                    <li class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
                        <a href="{{ route('crm.orders.edit', $row['order']) }}" wire:navigate class="btn-link flex-1">{{ $row['order']->number }}</a>
                        <span class="text-muted">{{ __('orders.statuses.'.$row['order']->status) }}</span>
                        <span class="tabular-nums">{{ Money::format($row['value']) }}</span>
                        <span class="tabular-nums {{ $row['balance'] > 0 ? 'text-warn' : ($row['balance'] < 0 ? 'text-bad' : 'text-ok') }}">{{ Money::format($row['balance']) }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
    <p class="text-xs text-muted">{{ __('finance.not_fiscal') }}</p>
</div>
