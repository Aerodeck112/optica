@php use App\Support\Money; use App\Domain\Clinical\PrescriptionService as Rx; @endphp
<div class="grid max-w-7xl gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
    <div class="min-w-0 space-y-6">
        <div aria-live="polite">
            @if ($notice)<div class="rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ $notice }}</div>@endif
            @if ($failure)<div class="rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad" role="alert">{{ $failure }}</div>@endif
        </div>

        <form wire:submit="save" class="space-y-6" data-preserve>
            <section class="panel space-y-5 p-5" aria-labelledby="ord-main">
                <div class="flex flex-wrap items-center gap-3">
                    <h2 id="ord-main" class="flex-1 text-base font-semibold">{{ $order ? $order->number : __('orders.create') }}</h2>
                    @if ($order)
                        <span class="{{ match ($order->status) { 'ready' => 'badge-ok', 'delivered', 'cancelled' => 'badge-neutral', 'new' => 'badge-warn', default => 'badge-info' } }}">{{ __('orders.statuses.'.$order->status) }}</span>
                        @if ($order->isDelayed())<span class="badge-bad">{{ __('orders.delayed') }}</span>@endif
                    @endif
                    <a href="{{ route('crm.orders.index') }}" wire:navigate class="btn-link text-sm">{{ __('crm.actions.back_to_list') }}</a>
                </div>

                <div>
                    <p class="field-label">{{ __('orders.patient') }} <span class="text-bad" aria-hidden="true">*</span></p>
                    @if ($patient)
                        <p class="text-sm"><a href="{{ route('crm.patients.show', ['patient' => $patient, 'tab' => 'overview']) }}" wire:navigate class="btn-link">{{ $patient->fullName() }}</a>
                            <span class="text-muted">· {{ $patient->code }} · {{ $patient->phone }}</span>
                            @if (! $order)<button type="button" class="btn-link ml-2 text-xs" wire:click="$set('patientId', null)">{{ __('orders.choose_patient') }}</button>@endif</p>
                    @else
                        <label for="pq" class="sr-only">{{ __('orders.search_patient') }}</label>
                        <input id="pq" type="search" class="input max-w-md" wire:model.live.debounce.300ms="patientQuery" placeholder="{{ __('patients.search_placeholder') }}" autocomplete="off">
                        @if ($patientResults->isNotEmpty())
                            <ul class="mt-2 max-w-md divide-y divide-line rounded-lg ring-1 ring-line" role="list">
                                @foreach ($patientResults as $r)
                                    <li><button type="button" class="block min-h-11 w-full px-3 py-2 text-left text-sm hover:bg-mist" wire:click="choosePatient({{ $r->id }})">{{ $r->fullName() }} <span class="text-muted">· {{ $r->birth_date?->format('d.m.Y') }} · {{ $r->phone }}</span></button></li>
                                @endforeach
                            </ul>
                        @endif
                        @error('patientId')<p class="field-error">{{ $message }}</p>@enderror
                    @endif
                </div>

                <div class="grid gap-5 md:grid-cols-3">
                    <div>
                        <label for="o-loc" class="field-label">{{ __('crm.fields.location') }}</label>
                        <select id="o-loc" wire:model="locationId" class="input" @disabled(! $editable)>
                            @foreach ($locations as $l)<option value="{{ $l->id }}">{{ $l->name() }}</option>@endforeach
                        </select>
                        @error('locationId')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="o-resp" class="field-label">{{ __('orders.responsible') }}</label>
                        <select id="o-resp" wire:model="responsibleId" class="input" @disabled(! $editable)>
                            <option value="">—</option>
                            @foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                        </select>
                    </div>
                    <x-admin.text model="dueOn" type="date" :label="__('orders.due_on')" :disabled="! $editable" />
                    @if ($patient)
                        <div class="md:col-span-2">
                            <label for="o-rx" class="field-label">{{ __('orders.prescription') }}</label>
                            <select id="o-rx" wire:model.live="prescriptionId" class="input" @disabled(! $editable)>
                                <option value="">{{ __('orders.no_prescription') }}</option>
                                @foreach ($prescriptions as $rx)
                                    <option value="{{ $rx->id }}">{{ __('orders.prescription_ref', ['type' => __('clinical.rx.types.'.$rx->type), 'version' => $rx->version, 'date' => $rx->issued_on->format('d.m.Y'), 'name' => $rx->specialist?->display_name]) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="o-appt" class="field-label">{{ __('orders.appointment') }}</label>
                            <select id="o-appt" wire:model="appointmentId" class="input" @disabled(! $editable)>
                                <option value="">—</option>
                                @foreach ($appointments as $a)<option value="{{ $a->id }}">{{ $a->starts_at->timezone($tz)->format('d.m.Y') }} · {{ $a->service?->name() }}</option>@endforeach
                            </select>
                        </div>
                    @endif
                </div>

                @if ($rxValues)
                    <div class="overflow-x-auto rounded-lg bg-paper p-3">
                        <p class="text-xs font-semibold text-muted">{{ __('orders.view_rx_values') }}</p>
                        <table class="mt-1 text-sm">
                            <tr><th class="pr-3"></th>@foreach (Rx::TYPE_FIELDS[$rxValues->type] as $f)<th scope="col" class="px-2 text-left font-medium text-muted">{{ __('clinical.rx.'.$f) }}</th>@endforeach</tr>
                            @foreach (['od', 'os'] as $eye)
                                <tr><th scope="row" class="pr-3 text-left">{{ strtoupper($eye) }}</th>@foreach (Rx::TYPE_FIELDS[$rxValues->type] as $f)<td class="px-2 tabular-nums">{{ Rx::display($f, $rxValues->data[$eye][$f] ?? '') }}</td>@endforeach</tr>
                            @endforeach
                        </table>
                        @if (($rxValues->data['pd'] ?? '') !== '')<p class="mt-1 text-sm">{{ __('clinical.rx.pd') }}: {{ $rxValues->data['pd'] }}</p>@endif
                    </div>
                @endif

                <div>
                    <label for="o-notes" class="field-label">{{ __('orders.notes') }}</label>
                    <textarea id="o-notes" wire:model="notes" rows="2" class="input" aria-describedby="o-notes-hint" @disabled(! $editable)></textarea>
                    <p id="o-notes-hint" class="field-hint">{{ __('orders.notes_hint') }}</p>
                </div>
            </section>

            <section class="panel p-5" aria-labelledby="ord-items">
                <h2 id="ord-items" class="text-base font-semibold">{{ __('orders.items') }}</h2>
                <ol class="mt-4 space-y-3" role="list">
                    @foreach ($items as $i => $item)
                        <li class="grid gap-3 rounded-lg bg-paper p-3 sm:grid-cols-[9rem_minmax(0,1fr)_5rem_8rem_auto] sm:items-end" wire:key="item-{{ $i }}">
                            <div>
                                <label for="it-kind-{{ $i }}" class="mb-1 block text-xs text-muted">{{ __('orders.item_kind') }}</label>
                                <select id="it-kind-{{ $i }}" wire:model="items.{{ $i }}.kind" class="input" @disabled(! $editable)>
                                    @foreach (\App\Models\OrderItem::KINDS as $k)<option value="{{ $k }}">{{ __('orders.kinds.'.$k) }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label for="it-desc-{{ $i }}" class="mb-1 block text-xs text-muted">{{ __('orders.item_description') }}</label>
                                <input id="it-desc-{{ $i }}" type="text" wire:model.blur="items.{{ $i }}.description" class="input" maxlength="300" @disabled(! $editable) @error("items.$i.description") aria-invalid="true" @enderror>
                            </div>
                            <div>
                                <label for="it-qty-{{ $i }}" class="mb-1 block text-xs text-muted">{{ __('orders.quantity') }}</label>
                                <input id="it-qty-{{ $i }}" type="number" min="1" max="99" wire:model.blur="items.{{ $i }}.quantity" class="input" @disabled(! $editable)>
                            </div>
                            <div>
                                <label for="it-price-{{ $i }}" class="mb-1 block text-xs text-muted">{{ __('orders.unit_price') }}</label>
                                <input id="it-price-{{ $i }}" type="text" inputmode="decimal" wire:model.blur="items.{{ $i }}.price" class="input text-right tabular-nums" @disabled(! $editable) @error("items.$i.price") aria-invalid="true" @enderror>
                            </div>
                            @if ($editable && count($items) > 1)
                                <button type="button" class="btn-link min-h-11 text-sm text-bad" wire:click="removeItem({{ $i }})" aria-label="{{ __('orders.remove_item') }} {{ $i + 1 }}">{{ __('cms.media.remove') }}</button>
                            @endif
                            @error("items.$i.description")<p class="field-error sm:col-span-5">{{ $message }}</p>@enderror
                            @error("items.$i.price")<p class="field-error sm:col-span-5">{{ $message }}</p>@enderror
                        </li>
                    @endforeach
                </ol>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    @if ($editable)<button type="button" class="btn-secondary" wire:click="addItem">{{ __('orders.add_item') }}</button>@endif
                    <p class="ml-auto text-base">{{ __('orders.total') }}: <strong class="tabular-nums">{{ Money::format($total) }}</strong></p>
                </div>
            </section>

            @if ($editable)
                @can('orders.manage')
                    <div class="flex flex-wrap gap-3">
                        <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ __('orders.save') }}</button>
                    </div>
                @endcan
            @endif
        </form>

        @if ($order)
            <section aria-labelledby="ord-hist">
                <h2 id="ord-hist" class="text-base font-semibold">{{ __('orders.history') }}</h2>
                <ol class="panel mt-3 divide-y divide-line" role="list">
                    @foreach ($order->events as $e)
                        <li class="px-4 py-3 text-sm">
                            <p class="text-xs text-muted">{{ $e->created_at->timezone($tz)->format('d.m.Y H:i') }} · {{ $e->user?->name }}</p>
                            <p>@if ($e->type === 'status'){{ __('orders.events.status', ['from' => __('orders.statuses.'.$e->from_status), 'to' => __('orders.statuses.'.$e->to_status)]) }}@else{{ __('orders.events.'.$e->type) }}@endif</p>
                            @if ($e->note)<p class="text-xs text-muted">{{ $e->note }}</p>@endif
                        </li>
                    @endforeach
                </ol>
            </section>
        @endif
    </div>

    @if ($order)
        {{-- On a phone the status and the money come first; the long form follows. --}}
        <aside class="order-first space-y-6 lg:order-none">
            @can('orders.change_status')
                @php($next = \App\Models\Order::TRANSITIONS[$order->status] ?? [])
                @if ($next)
                    <form wire:submit="changeStatus" class="panel space-y-3 p-4">
                        <label for="o-next" class="field-label">{{ __('orders.next_status') }}</label>
                        <select id="o-next" wire:model.live="nextStatus" class="input">
                            <option value="">—</option>
                            @foreach ($next as $s)<option value="{{ $s }}">{{ __('orders.statuses.'.$s) }}</option>@endforeach
                        </select>
                        <x-admin.text model="statusNote" :label="__('orders.status_note')" maxlength="300" />
                        @if ($nextStatus === 'delivered')
                            <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="allowUnpaid" class="mt-0.5 h-5 w-5 rounded border-line text-ink focus:ring-ink"> <span>{{ __('orders.allow_unpaid') }}</span></label>
                        @endif
                        <button type="submit" class="btn-primary w-full" @disabled(! $nextStatus)>{{ __('crm.actions.save') }}</button>
                    </form>
                @endif
            @endcan
            @can('payments.view')
                <livewire:crm.payments-panel payable-type="order" :payable-id="$order->id" :key="'pay-order-'.$order->id.'-'.$order->lock_version" />
            @endcan
            <div class="panel p-4 text-sm">
                <a href="{{ route('crm.orders.handover', $order) }}" target="_blank" rel="noopener" class="btn-link">{{ __('orders.handover') }} (PDF)</a>
                <p class="mt-1 text-xs text-muted">{{ __('finance.not_fiscal') }}</p>
            </div>
        </aside>
    @endif
</div>
