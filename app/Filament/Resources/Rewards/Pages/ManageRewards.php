<?php

namespace App\Filament\Resources\Rewards\Pages;

use App\Filament\Resources\Rewards\RewardResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRewards extends ManageRecords
{
    protected static string $resource = RewardResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
