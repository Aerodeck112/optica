<x-filament-panels::page>
    <p class="aw-muted">Trage un card în altă coloană sau folosește butonul „→” de pe card. „Pierdut” se marchează din fișa cererii, cu motiv. Coloanele „Finalizat” și „Pierdut” arată ultimele 30 de zile.</p>
    <div class="aw-kanban" x-data="{ dragging: null }">
        @php $order = array_keys($stages); @endphp
        @foreach ($stages as $stage => $label)
            @php $items = $leads[$stage] ?? collect(); $next = $order[array_search($stage, $order) + 1] ?? null; @endphp
            <section class="aw-kanban-col" aria-label="{{ $label }}"
                     x-on:dragover.prevent x-on:drop.prevent="dragging && $wire.move(dragging, '{{ $stage }}'); dragging = null">
                <h3 class="aw-h aw-row aw-between">{{ $label }} <span class="aw-badge">{{ $items->count() }}</span></h3>
                @foreach ($items as $lead)
                    <article class="aw-kanban-card" draggable="true" x-on:dragstart="dragging = {{ $lead->id }}">
                        <a class="aw-link" href="{{ $url($lead) }}" style="font-weight:600">{{ $lead->contact_name }}</a>
                        <div class="aw-muted" style="font-size:.8125rem">
                            {{ \App\Modules\Crm\Models\Lead::TYPES[$lead->type] ?? $lead->type }}
                            @if ($lead->participants) · {{ $lead->participants }} pers.@endif
                            @if ($lead->preferred_date) · {{ $lead->preferred_date->format('d.m.Y') }}@endif
                        </div>
                        @if ($lead->offer_amount_minor)<div style="font-size:.8125rem">Ofertă: {{ money($lead->offer_amount_minor) }}</div>@endif
                        @if ($next && $next !== 'lost' && ! in_array($stage, ['completed', 'lost']))
                            <button type="button" class="aw-link" style="font-size:.8125rem;margin-top:.25rem" wire:click="move({{ $lead->id }}, '{{ $next }}')">→ {{ $stages[$next] }}</button>
                        @endif
                    </article>
                @endforeach
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
