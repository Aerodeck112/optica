<x-filament-panels::page>
    <div class="aw-sale">
        <section class="aw-card aw-stack">
            <div class="aw-row aw-between">
                <input type="search" wire:model.live.debounce.300ms="search" class="aw-select aw-grow" placeholder="Caută produs (nume sau cod)" aria-label="Caută produs" style="font-size:1rem;padding:.6rem .8rem;min-width:14rem">
                @if ($warehouses->count() > 1)
                    <label class="aw-inline-label">Gestiune
                        <select wire:model.live="warehouseId" class="aw-select">
                            @foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach
                        </select>
                    </label>
                @endif
            </div>
            @if ($items->isEmpty())
                <p class="aw-muted">Nu există produse de vânzare. În <strong>Stocuri → Articole</strong> completați „Preț de vânzare (cu TVA)” la băuturi, gustări etc.;
                    stocul vine din recepții (NIR).</p>
            @else
                <div class="aw-sale-grid">
                    @foreach ($items as $item)
                        @php
                            $stock = $item->track_stock ? (float) ($levels[$item->id] ?? 0) : null;
                            $inCart = (int) ($cart[$item->id] ?? 0);
                            $out = $stock !== null && $stock - $inCart <= 0;
                        @endphp
                        <button type="button" class="aw-sale-tile" wire:click="add({{ $item->id }})" @disabled($out) aria-label="Adaugă {{ $item->name }}">
                            <strong>{{ $item->name }}</strong>
                            <span class="aw-sale-price">{{ money($item->sale_price_minor) }}</span>
                            <span class="aw-muted">@if ($stock === null) fără stoc urmărit @else stoc: {{ rtrim(rtrim(number_format($stock, 3, ',', '.'), '0'), ',') }} {{ $item->unit?->symbol }}@endif</span>
                            @if ($inCart)<span class="aw-sale-count">{{ $inCart }}</span>@endif
                        </button>
                    @endforeach
                </div>
            @endif
        </section>

        <aside class="aw-card aw-stack aw-sale-cart">
            <h3 style="font-weight:700;font-size:1.05rem">Bon curent</h3>
            @forelse ($lines as $id => $line)
                <div class="aw-row aw-between" style="gap:.5rem">
                    <div class="aw-grow"><strong>{{ $line['item']->name }}</strong><br><span class="aw-muted">{{ money($line['item']->sale_price_minor) }} × {{ $line['qty'] }}</span></div>
                    <div class="aw-row" style="gap:.25rem">
                        <x-filament::icon-button icon="heroicon-o-minus" size="sm" color="gray" wire:click="decrement({{ $id }})" label="Scade" />
                        <span style="min-width:1.5rem;text-align:center;font-weight:700">{{ $line['qty'] }}</span>
                        <x-filament::icon-button icon="heroicon-o-plus" size="sm" color="gray" wire:click="add({{ $id }})" label="Adaugă" />
                        <x-filament::icon-button icon="heroicon-o-trash" size="sm" color="danger" wire:click="remove({{ $id }})" label="Scoate" />
                    </div>
                    <strong style="min-width:5rem;text-align:right">{{ money($line['amount']) }}</strong>
                </div>
            @empty
                <p class="aw-muted">Atingeți un produs ca să-l adăugați.</p>
            @endforelse

            <div class="aw-row aw-between" style="border-top:1px solid var(--gray-200);padding-top:.75rem">
                <span style="font-weight:600">Total</span><strong style="font-size:1.5rem">{{ money($total) }}</strong>
            </div>
            <div class="aw-seg" role="group" aria-label="Metoda de plată">
                @foreach ($methods as $key => $label)
                    <button type="button" wire:click="$set('method', '{{ $key }}')" aria-pressed="{{ $method === $key ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
            @if ($bookings->isNotEmpty())
                <label class="aw-inline-label" style="display:flex">Pentru rezervarea (opțional)
                    <select wire:model="bookingId" class="aw-select aw-grow">
                        <option value="">— fără —</option>
                        @foreach ($bookings as $b)<option value="{{ $b->id }}">{{ \App\Support\LocalTime::format($b->starts_at, 'H:i') }} · {{ $b->contact_name }} · {{ $b->code }}</option>@endforeach
                    </select>
                </label>
            @endif
            <label class="aw-inline-label"><input type="checkbox" wire:model="printReceipt" @disabled(! $hasDevice)> Emite bon fiscal @unless ($hasDevice)<span class="aw-muted">(nicio casă de marcat activă)</span>@endunless</label>
            <div class="aw-row">
                <x-filament::button size="xl" color="success" icon="heroicon-o-banknotes" wire:click="checkout" wire:loading.attr="disabled" wire:target="checkout" :disabled="$lines->isEmpty()" class="aw-grow">Încasează {{ money($total) }}</x-filament::button>
                @if ($lines->isNotEmpty())<x-filament::button color="gray" wire:click="clear">Golește</x-filament::button>@endif
            </div>
        </aside>
    </div>

    <section class="aw-card">
        <h3 style="font-weight:700;margin-bottom:.5rem">Vânzările de azi</h3>
        @if ($sales->isEmpty())
            <p class="aw-muted">Nicio vânzare azi.</p>
        @else
            <table class="aw-table">
                <thead><tr><th>Ora</th><th>Produse</th><th>Total</th><th>Plată</th><th>Bon fiscal</th><th>Vânzător</th><th><span class="sr-only">Acțiuni</span></th></tr></thead>
                <tbody>
                    @foreach ($sales as $sale)
                        @php $receipt = ($receipts[$sale->payment_id] ?? collect())->sortByDesc('id')->first(); @endphp
                        <tr @if ($sale->status === 'cancelled') style="opacity:.55" @endif>
                            <td>{{ \App\Support\LocalTime::format($sale->created_at, 'H:i') }}</td>
                            <td>{{ $sale->lines->map(fn ($l) => $l->name.' × '.rtrim(rtrim((string) $l->quantity, '0'), '.'))->implode(', ') }}
                                @if ($sale->status === 'cancelled')<br><span class="aw-badge aw-badge--bad">Anulată: {{ $sale->cancel_reason }}</span>@endif</td>
                            <td style="font-weight:600">{{ money($sale->total_minor) }}</td>
                            <td>{{ $methods[$sale->method] ?? $sale->method }}</td>
                            <td>
                                @if ($receipt)
                                    <span class="aw-badge {{ in_array($receipt->status, ['printed', 'reconciled_printed']) ? 'aw-badge--ok' : (in_array($receipt->status, ['failed', 'uncertain']) ? 'aw-badge--bad' : 'aw-badge--warn') }}">{{ \App\Filament\Resources\FiscalReceipts\FiscalReceiptResource::STATUSES[$receipt->status] ?? $receipt->status }}{{ $receipt->fiscal_number ? ' · nr. '.$receipt->fiscal_number : '' }}</span>
                                @else
                                    <span class="aw-muted">fără bon</span>
                                @endif
                            </td>
                            <td>{{ $sale->seller?->name }}</td>
                            <td class="aw-row" style="gap:.5rem">
                                @if ($sale->status === 'completed' && (! $receipt || in_array($receipt->status, ['failed', 'reconciled_not_printed'])) && $hasDevice)
                                    <x-filament::button size="xs" color="gray" wire:click="reissue({{ $sale->id }})">Emite bonul</x-filament::button>
                                @endif
                                @if ($canCancel && $sale->status === 'completed')
                                    <x-filament::button size="xs" color="danger" x-data x-on:click="const r = prompt('Motivul anulării (ex. produs greșit):'); if (r) { $wire.cancelSale({{ $sale->id }}, r) }">Anulează</x-filament::button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="aw-muted" style="margin-top:.5rem">Total azi (fără anulări): <strong>{{ money($sales->where('status', 'completed')->sum('total_minor')) }}</strong></p>
        @endif
    </section>
</x-filament-panels::page>
