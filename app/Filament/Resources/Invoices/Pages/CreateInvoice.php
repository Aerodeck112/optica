<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Crm\Models\Customer;
use App\Modules\Finance\Services\InvoiceService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected static ?string $title = 'Ciornă de factură';

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(InvoiceService::class)->createDraft(
                InvoiceResource::linesFromForm($data['lines'] ?? []),
                isset($data['customer_id']) ? Customer::query()->find($data['customer_id']) : null,
                array_filter(['due_date' => $data['due_date'] ?? null, 'payment_terms' => $data['payment_terms'] ?? null, 'notes' => $data['notes'] ?? null]),
                auth()->id(),
            );
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return InvoiceResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
