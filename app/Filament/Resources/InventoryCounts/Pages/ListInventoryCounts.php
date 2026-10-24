<?php

namespace App\Filament\Resources\InventoryCounts\Pages;

use App\Filament\Resources\InventoryCounts\InventoryCountResource;
use Filament\Resources\Pages\ListRecords;

class ListInventoryCounts extends ListRecords
{
    protected static string $resource = InventoryCountResource::class;

    protected function getHeaderActions(): array
    {
        return [InventoryCountResource::startAction()];
    }
}
