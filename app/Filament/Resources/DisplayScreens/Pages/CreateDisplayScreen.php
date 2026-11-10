<?php

namespace App\Filament\Resources\DisplayScreens\Pages;

use App\Filament\Resources\DisplayScreens\DisplayScreenResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDisplayScreen extends CreateRecord
{
    protected static string $resource = DisplayScreenResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['settings'] = array_filter((array) ($data['settings'] ?? []), fn ($v) => $v !== null && $v !== '');

        return $data;
    }
}
