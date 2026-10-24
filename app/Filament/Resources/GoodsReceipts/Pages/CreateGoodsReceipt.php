<?php

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Modules\Inventory\Models\GoodsReceipt;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateGoodsReceipt extends CreateRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $receipt = GoodsReceipt::query()->create([
                'warehouse_id' => $data['warehouse_id'], 'supplier_id' => $data['supplier_id'] ?? null, 'received_on' => $data['received_on'] ?? null,
                'notes' => $data['notes'] ?? null, 'status' => 'draft', 'origin' => 'manual', 'received_by' => auth()->id(),
            ]);
            GoodsReceiptResource::saveLines($receipt, $data['lines']);

            return $receipt;
        });
    }

    protected function getRedirectUrl(): string
    {
        return GoodsReceiptResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
