<?php

namespace App\Filament\Resources\DisplayScreens\Pages;

use App\Filament\Resources\DisplayScreens\DisplayScreenResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDisplayScreens extends ListRecords
{
    protected static string $resource = DisplayScreenResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Ecran nou')];
    }
}
