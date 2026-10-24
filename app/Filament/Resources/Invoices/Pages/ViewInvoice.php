<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceActions;
use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record->full_number ? 'Factura '.$record->full_number : 'Ciornă de factură';
    }

    protected function getHeaderActions(): array
    {
        $refresh = fn () => $this->record = $this->getRecord()->refresh();

        return [
            EditAction::make()->label('Modifică ciorna')->visible(fn () => $this->getRecord()->status === 'draft'),
            InvoiceActions::issue()->after($refresh),
            InvoiceActions::pdf(),
            InvoiceActions::efactura()->after($refresh),
            InvoiceActions::storno(),
            InvoiceActions::exempt()->after($refresh),
        ];
    }
}
