<?php

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewGoodsReceipt extends ViewRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->number ? 'Recepția '.$this->getRecord()->number : 'Ciornă de recepție';
    }

    protected function getHeaderActions(): array
    {
        $refresh = fn () => $this->record = $this->getRecord()->refresh();

        return [
            EditAction::make()->visible(fn () => GoodsReceiptResource::canEdit($this->getRecord())),
            GoodsReceiptResource::confirmAction()->after($refresh),
            GoodsReceiptResource::printAction(),
            GoodsReceiptResource::cancelAction()->after($refresh),
        ];
    }
}
