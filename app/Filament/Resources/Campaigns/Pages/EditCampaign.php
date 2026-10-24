<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Filament\Resources\Campaigns\CampaignResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCampaign extends EditRecord
{
    protected static string $resource = CampaignResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // orice modificare de conținut cere un nou test înainte de programare
        return $data + ['test_sent_at' => null];
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
