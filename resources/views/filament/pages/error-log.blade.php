<x-filament-panels::page>
    @php $entries = $this->entries(); @endphp
    <x-filament::section>
        <p class="aw-muted">Ultimele erori înregistrate de aplicație (cele mai noi primele). Dacă ceva nu funcționează, trimiteți dezvoltatorului o captură a acestei pagini.</p>
    </x-filament::section>
    <x-filament::section>
        @if (! $entries)
            <p>Nicio eroare înregistrată în ultimele zile.</p>
        @else
            <div class="aw-stack">
                @foreach ($entries as $entry)
                    <div style="border-top:1px solid rgb(128 128 128 / .2);padding-top:.75rem">
                        <div class="aw-muted" style="font-size:.8rem">{{ $entry['at'] }} · {{ $entry['level'] }}@if ($entry['url']) · {{ $entry['url'] }}@endif</div>
                        <div style="font-family:ui-monospace,monospace;font-size:.85rem;word-break:break-word">{{ $entry['message'] }}</div>
                        @if ($entry['location'])<div class="aw-muted" style="font-family:ui-monospace,monospace;font-size:.75rem">{{ $entry['location'] }}</div>@endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
