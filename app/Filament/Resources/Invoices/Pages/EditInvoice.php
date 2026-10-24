<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    public function getTitle(): string
    {
        return 'Ciornă de factură';
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->getRecord()->load('lines');
        $data['lines'] = InvoiceResource::linesToForm($invoice);
        $data['due_date'] = $invoice->due_date?->toDateString();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            DB::transaction(function () use ($record, $data) {
                $record->forceFill([
                    'customer_id' => $data['customer_id'] ?? null,
                    'due_date' => $data['due_date'] ?? null,
                    'payment_terms' => $data['payment_terms'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ])->save();
                app(InvoiceService::class)->replaceLines($record, InvoiceResource::linesFromForm($data['lines'] ?? []));
            });
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            throw new Halt;
        }

        return $record->refresh();
    }

    protected function getRedirectUrl(): string
    {
        return InvoiceResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->label('Șterge ciorna')];
    }
}
