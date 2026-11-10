<?php

namespace App\Filament\Resources\DisplayScreens\Pages;

use App\Filament\Resources\DisplayScreens\DisplayScreenResource;
use App\Modules\Audit\AuditLogger;
use App\Modules\Display\Models\DisplayScreen;
use Filament\Resources\Pages\EditRecord;

class EditDisplayScreen extends EditRecord
{
    protected static string $resource = DisplayScreenResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // comutatoarele pornesc de la valorile efective (implicite + salvate), nu de la „gol”
        $data['settings'] = array_merge(DisplayScreen::DEFAULTS, (array) ($data['settings'] ?? []));

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // valorile goale revin la implicit
        $data['settings'] = array_filter((array) ($data['settings'] ?? []), fn ($v) => $v !== null && $v !== '');

        return $data;
    }

    protected function afterSave(): void
    {
        app(AuditLogger::class)->log('display.settings_updated', $this->getRecord(), ['settings' => $this->getRecord()->settings]);
    }
}
