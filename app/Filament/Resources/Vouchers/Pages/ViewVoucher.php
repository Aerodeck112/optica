<?php

namespace App\Filament\Resources\Vouchers\Pages;

use App\Filament\Resources\Vouchers\VoucherResource;
use Filament\Resources\Pages\ViewRecord;

class ViewVoucher extends ViewRecord
{
    protected static string $resource = VoucherResource::class;

    public function getTitle(): string
    {
        return 'Voucher '.$this->getRecord()->code;
    }

    protected function getHeaderActions(): array
    {
        return array_map(fn ($action) => $action->after(fn () => $this->getRecord()->refresh()), VoucherResource::actions());
    }
}
