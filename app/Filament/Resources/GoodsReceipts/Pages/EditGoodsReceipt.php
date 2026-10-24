<?php

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditGoodsReceipt extends EditRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['lines'] = GoodsReceiptResource::linesToForm($this->getRecord()->load('lines'));

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        DB::transaction(function () use ($record, $data) {
            $record->forceFill(['warehouse_id' => $data['warehouse_id'], 'received_on' => $data['received_on'] ?? null, 'notes' => $data['notes'] ?? null]
                + ($record->purchase_invoice_id ? [] : ['supplier_id' => $data['supplier_id'] ?? null]))->save();
            GoodsReceiptResource::saveLines($record, $data['lines']);
        });

        return $record->refresh();
    }

    protected function getRedirectUrl(): string
    {
        return GoodsReceiptResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->label('Șterge ciorna')];
    }
}
