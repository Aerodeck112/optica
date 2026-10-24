<x-filament-panels::page>
    <div class="aw-stack">
        {{ $this->form }}
        <x-filament::section heading="Descarcă">
            <div class="aw-row" style="flex-wrap:wrap">
                {{ $this->invoicesCsvAction }}
                {{ $this->paymentsCsvAction }}
                {{ $this->purchasesCsvAction }}
                {{ $this->documentsZipAction }}
            </div>
            <p class="aw-muted" style="margin-top:1rem">Fișierele CSV se deschid în Excel (separator „;”, diacritice păstrate). Bonurile din simulator sunt marcate explicit și nu sunt bonuri fiscale.</p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
