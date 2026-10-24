<?php

namespace App\Filament\Resources\FiscalDevices\Pages;

use App\Filament\Resources\FiscalDevices\FiscalDeviceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageFiscalDevices extends ManageRecords
{
    protected static string $resource = FiscalDeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
