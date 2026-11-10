<?php

namespace App\Filament\Resources\Players\Pages;

use App\Filament\Resources\Players\PlayerResource;
use App\Modules\Arena\Services\PlayerException;
use App\Modules\Arena\Services\PlayerService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreatePlayer extends CreateRecord
{
    protected static string $resource = PlayerResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(PlayerService::class)->create($data, 'staff', auth()->id());
        } catch (PlayerException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            throw new Halt;
        }
    }
}
