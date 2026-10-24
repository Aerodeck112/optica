<?php

namespace App\Filament\Resources\LoyaltyTiers\Pages;

use App\Filament\Resources\LoyaltyTiers\LoyaltyTierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLoyaltyTiers extends ManageRecords
{
    protected static string $resource = LoyaltyTierResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
