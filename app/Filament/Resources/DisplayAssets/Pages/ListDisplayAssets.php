<?php

namespace App\Filament\Resources\DisplayAssets\Pages;

use App\Filament\Resources\DisplayAssets\DisplayAssetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDisplayAssets extends ListRecords
{
    protected static string $resource = DisplayAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Material nou')];
    }
}
