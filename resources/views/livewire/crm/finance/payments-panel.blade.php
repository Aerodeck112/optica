@php use App\Support\Money; @endphp
<section class="panel p-4" aria-label="{{ __('finance.summary') }}">
    <dl class="grid grid-cols-3 gap-2 text-center">
        <div class="rounded-lg bg-paper px-2 py-3"><dt class="text-xs text-muted">{{ __('finance.value') }}</dt><dd class="mt-1 whitespace-nowrap text-sm font-semibold tabular-nums">{{ Money::format($summary['value']) }}</dd></div>
        <div class="rounded-lg bg-paper px-2 py-3"><dt class="text-xs text-muted">{{ __('finance.paid') }}</dt><dd class="mt-1 whitespace-nowrap text-sm font-semibold tabular-nums">{{ Money::format($summary['paid']) }}</dd></div>
        <div @class(['rounded-lg px-2 py-3', 'bg-warn-bg text-warn' => $summary['balance'] > 0, 'bg-bad-bg text-bad' => $summary['balance'] < 0, 'bg-ok-bg text-ok' => $summary['balance'] === 0])>
            <dt class="text-xs">{{ $summary['balance'] < 0 ? __('orders.credit') : __('finance.balance') }}</dt>
            <dd class="mt-1 whitespace-nowrap text-sm font-semibold tabular-nums">{{ Money::format(abs($summary['balance'])) }}</dd>
        </div>
    </dl>

    <div aria-live="polite">
        @if ($notice)<p class="mt-3 rounded-lg bg-ok-bg px-3 py-2 text-sm text-ok" role="status">{{ $notice }}</p>@endif
    </div>

    @if ($mode)
        <form wire:submit="save" class="mt-4 space-y-3 border-t border-line pt-4">
            <p class="text-sm font-semibold">{{ $mode === 'refund' ? __('finance.record_refund') : __('finance.record_payment') }}</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <x-admin.text model="amount" inputmode="decimal" :label="__('finance.amount')" required />
                <div>
                    <label for="pm-method" class="field-label">{{ __('finance.method') }}</label>
                    <select id="pm-method" wire:model="method" class="input">
                        @foreach (\App\Models\Payment::METHODS as $m)<option value="{{ $m }}">{{ __('finance.methods.'.$m) }}</option>@endforeach
                    </select>
                </div>
            </div>
            @if ($mode === 'payment')<x-admin.text model="reference" :label="__('finance.reference')" />@endif
            <x-admin.text model="note" :label="$mode === 'refund' ? __('finance.refund_reason') : __('finance.note')" :required="$mode === 'refund'" />
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ __('crm.actions.save') }}</button>
                <button type="button" class="btn-secondary" wire:click="$set('mode', null)">{{ __('crm.actions.cancel') }}</button>
            </div>
        </form>
    @else
        <div class="mt-4 flex flex-wrap gap-2">
            @can('payments.create')
                @if ($summary['balance'] > 0)<button type="button" class="btn-primary" wire:click="open('payment')">{{ __('finance.record_payment') }}</button>@endif
            @endcan
            @can('payments.refund')
                @if ($summary['paid'] > 0)<button type="button" class="btn-secondary" wire:click="open('refund')">{{ __('finance.record_refund') }}</button>@endif
            @endcan
        </div>
    @endif

    @if ($payments->isNotEmpty())
        <ol class="mt-4 divide-y divide-line border-t border-line text-sm" role="list">
            @foreach ($payments as $p)
                <li class="py-2" wire:key="pay-{{ $p->id }}">
                    <div class="flex flex-wrap items-baseline gap-x-3">
                        <span @class(['font-semibold tabular-nums', 'line-through text-muted' => $p->voided_at])>{{ $p->type === 'refund' ? '−' : '' }}{{ Money::format($p->amount_cents) }}</span>
                        <span class="text-muted">{{ __('finance.types.'.$p->type) }} · {{ __('finance.methods.'.$p->method) }}@if ($p->is_advance) · {{ __('finance.advance') }}@endif</span>
                        @if (! $p->voided_at)
                            @can('payments.refund')<button type="button" class="btn-link ml-auto text-xs text-muted" wire:click="startVoid({{ $p->id }})">{{ __('finance.void') }}</button>@endcan
                        @endif
                    </div>
                    <p class="text-xs text-muted">{{ $p->received_at->timezone($tz)->format('d.m.Y H:i') }} · {{ $p->operator?->name }}@if ($p->reference) · {{ $p->reference }}@endif @if ($p->note) · {{ $p->note }}@endif</p>
                    @if ($p->voided_at)<p class="text-xs text-bad">{{ __('finance.voided', ['reason' => $p->void_reason]) }} ({{ $p->voider?->name }}, {{ $p->voided_at->timezone($tz)->format('d.m.Y H:i') }})</p>@endif
                    @if ($voiding === $p->id)
                        <form wire:submit="void" class="mt-2 flex flex-wrap items-end gap-2">
                            <div class="min-w-48 flex-1"><x-admin.text model="voidReason" :label="__('finance.void_reason')" required /></div>
                            <button type="submit" class="btn-danger" wire:confirm="{{ __('finance.confirm_void') }}">{{ __('finance.void') }}</button>
                            <button type="button" class="btn-secondary" wire:click="cancelVoid">{{ __('crm.actions.cancel') }}</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</section>
