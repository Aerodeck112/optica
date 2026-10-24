<?php

namespace App\Filament\Resources\PurchaseInvoices\Pages;

use App\Filament\Resources\PurchaseInvoices\PurchaseInvoiceResource;
use App\Modules\Inventory\Services\InventoryException;
use App\Modules\Inventory\Services\PurchaseInvoiceService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditPurchaseInvoice extends EditRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['lines'] = PurchaseInvoiceResource::linesToForm($this->getRecord()->load('lines'));

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            DB::transaction(function () use ($record, $data) {
                $record->forceFill(['due_date' => $data['due_date'] ?? null, 'notes' => $data['notes'] ?? null])->save();
                app(PurchaseInvoiceService::class)->replaceLines($record, $data['lines'], $record->source === 'efactura' ? $record->gross_total_minor : null);
            });
        } catch (InventoryException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            throw new Halt;
        }

        return $record->refresh();
    }

    protected function getRedirectUrl(): string
    {
        return PurchaseInvoiceResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
