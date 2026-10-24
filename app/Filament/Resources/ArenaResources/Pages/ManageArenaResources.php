<?php

namespace App\Filament\Resources\ArenaResources\Pages;

use App\Filament\Resources\ArenaResources\ArenaResourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageArenaResources extends ManageRecords
{
    protected static string $resource = ArenaResourceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
