<x-filament-panels::page>
    <form wire:submit="save" class="aw-stack">
        {{ $this->form }}
        <div class="aw-row">
            <x-filament::button type="submit">Salvează</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
