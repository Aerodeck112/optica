<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Filament\Resources\Campaigns\CampaignResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCampaign extends CreateRecord
{
    protected static string $resource = CampaignResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['status' => 'draft', 'locale' => 'ro', 'created_by' => auth()->id()];
    }

    protected function getRedirectUrl(): string
    {
        return CampaignResource::getUrl('index');
    }
}
