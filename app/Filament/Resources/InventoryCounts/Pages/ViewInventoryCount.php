<?php

namespace App\Filament\Resources\InventoryCounts\Pages;

use App\Filament\Resources\InventoryCounts\InventoryCountResource;
use Filament\Resources\Pages\ViewRecord;

class ViewInventoryCount extends ViewRecord
{
    protected static string $resource = InventoryCountResource::class;

    public function getTitle(): string
    {
        return 'Inventar '.$this->getRecord()->warehouse?->name.' — '.$this->getRecord()->counted_on?->format('d.m.Y');
    }

    protected function getHeaderActions(): array
    {
        return [InventoryCountResource::confirmAction()->after(fn () => $this->redirect(InventoryCountResource::getUrl('view', ['record' => $this->getRecord()])))];
    }
}
