<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Modules\Audit\AuditLogger;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return CustomerResource::normalize($data);
    }

    protected function afterSave(): void
    {
        app(AuditLogger::class)->log('customer.updated', $this->getRecord(), ['fields' => array_keys($this->getRecord()->getChanges())]);
    }

    protected function getRedirectUrl(): string
    {
        return CustomerResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
