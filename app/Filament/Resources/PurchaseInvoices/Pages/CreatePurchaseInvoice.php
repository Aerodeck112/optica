<?php

namespace App\Filament\Resources\PurchaseInvoices\Pages;

use App\Filament\Resources\PurchaseInvoices\PurchaseInvoiceResource;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\InventoryException;
use App\Modules\Inventory\Services\PurchaseInvoiceService;
use App\Support\Money;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreatePurchaseInvoice extends CreateRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(PurchaseInvoiceService::class)->register(
                Supplier::query()->findOrFail($data['supplier_id']),
                [
                    'number' => $data['number'], 'issue_date' => $data['issue_date'], 'due_date' => $data['due_date'] ?? null, 'notes' => $data['notes'] ?? null,
                    'gross_total_minor' => filled($data['declared_total'] ?? null) ? Money::parse($data['declared_total']) : null,
                ],
                $data['lines'],
                auth()->id(),
            );
        } catch (InventoryException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return PurchaseInvoiceResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
