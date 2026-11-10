<?php

namespace App\Filament\Resources\DisplayPlaylists\Pages;

use App\Filament\Resources\DisplayPlaylists\DisplayPlaylistResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDisplayPlaylists extends ListRecords
{
    protected static string $resource = DisplayPlaylistResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Playlist nou')];
    }
}
