<?php

namespace App\Filament\Resources\VatRates\Pages;

use App\Filament\Resources\VatRates\VatRateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageVatRates extends ManageRecords
{
    protected static string $resource = VatRateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
