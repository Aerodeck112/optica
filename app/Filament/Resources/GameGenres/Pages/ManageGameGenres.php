<?php

namespace App\Filament\Resources\GameGenres\Pages;

use App\Filament\Resources\GameGenres\GameGenreResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageGameGenres extends ManageRecords
{
    protected static string $resource = GameGenreResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
