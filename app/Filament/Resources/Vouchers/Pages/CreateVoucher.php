<?php

namespace App\Filament\Resources\Vouchers\Pages;

use App\Filament\Resources\Vouchers\VoucherResource;
use App\Modules\Cms\Models\LegalDocument;
use App\Modules\Loyalty\Services\VoucherService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateVoucher extends CreateRecord
{
    protected static string $resource = VoucherResource::class;

    protected static ?string $title = 'Emite voucher';

    protected function handleRecordCreation(array $data): Model
    {
        // voucherul păstrează versiunea condițiilor în vigoare la emitere
        $data['legal_document_version_id'] = LegalDocument::current('voucher_terms')?->id;

        return app(VoucherService::class)->issue($data, auth()->id());
    }

    protected function getRedirectUrl(): string
    {
        return VoucherResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
