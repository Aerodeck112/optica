<?php

namespace App\Filament\Resources\GameSessions\Pages;

use App\Filament\Resources\GameSessions\GameSessionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGameSessions extends ListRecords
{
    protected static string $resource = GameSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Sesiune nouă')];
    }
}
