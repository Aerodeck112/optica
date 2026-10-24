<?php

namespace App\Filament\Resources\PurchaseInvoices\Pages;

use App\Filament\Resources\PurchaseInvoices\PurchaseInvoiceResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseInvoice extends ViewRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;

    public function getTitle(): string
    {
        return 'Factura '.$this->getRecord()->number.' — '.$this->getRecord()->supplier?->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Corectează liniile')->visible(fn () => PurchaseInvoiceResource::canEdit($this->getRecord())),
            PurchaseInvoiceResource::receiveAction(),
        ];
    }
}
