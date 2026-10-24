<?php

namespace App\Filament\Resources\ContactImports\Pages;

use App\Filament\Resources\ContactImports\ContactImportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContactImports extends ListRecords
{
    protected static string $resource = ContactImportResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Import nou')];
    }
}
