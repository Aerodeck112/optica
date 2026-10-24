<x-filament-panels::page>
    <div class="aw-stack">
        <x-filament::section>
            <div class="aw-row" style="justify-content:space-between">
                <div>
                    <div class="aw-muted">Versiunea instalată</div>
                    <div style="font-size:1.5rem;font-weight:700">{{ $this->currentVersion() }}</div>
                </div>
                <p class="aw-muted" style="max-width:34rem">Pachetele de actualizare pot modifica doar fișierele aplicației. Configurația (.env), fișierele încărcate și copiile de siguranță nu sunt atinse. Înainte de fiecare actualizare se face automat o copie de siguranță.</p>
            </div>
        </x-filament::section>

        <x-filament::section heading="1. Încărcați pachetul">
            {{ $this->form }}
            <div class="aw-row" style="margin-top:1rem">{{ $this->inspectAction }}</div>
        </x-filament::section>

        @if ($package)
            <x-filament::section heading="2. Verificați și aplicați">
                <dl class="aw-stack">
                    <div><dt class="aw-muted">Versiune nouă</dt><dd style="font-weight:700">{{ $package['version'] }}</dd></div>
                    @if ($package['description'])<div><dt class="aw-muted">Ce conține</dt><dd>{{ $package['description'] }}</dd></div>@endif
                    <div><dt class="aw-muted">Fișiere</dt><dd>{{ $package['files'] }} actualizate{{ $package['delete'] ? ', '.$package['delete'].' șterse' : '' }}{{ $package['migrate'] ? '; baza de date se actualizează' : '' }}</dd></div>
                    <div><dt class="aw-muted">Exemple</dt><dd style="font-family:ui-monospace,monospace;font-size:.8rem">{!! collect($package['sample'])->map(fn ($f) => e($f))->implode('<br>') !!}{{ $package['files'] > count($package['sample']) ? ' …' : '' }}</dd></div>
                    <div><dt class="aw-muted">Amprentă SHA-256</dt><dd style="font-family:ui-monospace,monospace;font-size:.75rem;word-break:break-all">{{ $package['sha256'] }}</dd></div>
                </dl>
                <div class="aw-row" style="margin-top:1rem">{{ $this->applyAction }}</div>
            </x-filament::section>
        @endif

        <x-filament::section heading="Istoric">
            @php $history = $this->history(); $lastApplied = $history->firstWhere('status', 'applied')?->id; @endphp
            @if ($history->isEmpty())
                <p class="aw-muted">Nicio actualizare aplicată din panou.</p>
            @else
                <div class="aw-stack">
                    @foreach ($history as $update)
                        <div class="aw-row" style="justify-content:space-between;border-top:1px solid rgb(128 128 128 / .2);padding-top:.75rem">
                            <div>
                                <strong>{{ $update->previous_version }} → {{ $update->version }}</strong>
                                <span class="aw-muted"> · {{ $update->created_at->timezone(config('aw.timezone'))->format('d.m.Y H:i') }} · {{ $update->user?->name }}</span>
                                <div>
                                    @switch($update->status)
                                        @case('applied') <x-filament::badge color="success">Aplicată</x-filament::badge> @break
                                        @case('rolled_back') <x-filament::badge color="gray">Anulată (revenire)</x-filament::badge> @break
                                        @default <x-filament::badge color="danger">Eșuată</x-filament::badge>
                                    @endswitch
                                </div>
                                @if ($update->description)<div class="aw-muted">{{ $update->description }}</div>@endif
                                @if ($update->log)<div class="aw-muted" style="font-size:.8rem">{{ implode(' ', $update->log) }}</div>@endif
                            </div>
                            @if ($update->id === $lastApplied)
                                {{ ($this->rollbackAction)(['update' => $update->id]) }}
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
