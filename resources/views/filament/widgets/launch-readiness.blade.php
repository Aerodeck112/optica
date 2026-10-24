<x-filament-widgets::widget>
    <x-filament::section heading="Pregătire pentru lansare" description="Ce mai trebuie completat sau configurat.">
        <ul class="aw-stack" style="--gap:.5rem;list-style:none;margin:0;padding:0">
            @foreach ($items as $item)
                <li class="aw-row" style="gap:.5rem;align-items:flex-start;flex-wrap:nowrap">
                    <span class="aw-badge {{ $item['ok'] ? 'aw-badge--ok' : 'aw-badge--warn' }}" aria-hidden="true">{{ $item['ok'] ? '✓' : '!' }}</span>
                    <span><span class="sr-only">{{ $item['ok'] ? 'Gata:' : 'De făcut:' }}</span>
                        @if ($item['url'])<a class="aw-link" href="{{ $item['url'] }}">{{ $item['label'] }}</a>@else{{ $item['label'] }}@endif
                    </span>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
