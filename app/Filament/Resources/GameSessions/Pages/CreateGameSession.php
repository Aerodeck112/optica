<?php

namespace App\Filament\Resources\GameSessions\Pages;

use App\Filament\Resources\GameSessions\GameSessionResource;
use App\Modules\Arena\Services\GameSessionService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateGameSession extends CreateRecord
{
    protected static string $resource = GameSessionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(GameSessionService::class)->create($data, auth()->id());
    }

    protected function getRedirectUrl(): string
    {
        return GameSessionResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
