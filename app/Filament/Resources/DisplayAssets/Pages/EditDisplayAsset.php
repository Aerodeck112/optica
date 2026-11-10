<?php

namespace App\Filament\Resources\DisplayAssets\Pages;

use App\Filament\Resources\DisplayAssets\DisplayAssetResource;
use App\Modules\Cms\Models\Media;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditDisplayAsset extends EditRecord
{
    protected static string $resource = DisplayAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->label('Scoate din bibliotecă')->modalDescription('Fișierul rămâne în biblioteca media.')];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $media = Media::query()->findOrFail($data['media_id']);
        if ($problem = DisplayAssetResource::assertMedia($media, $data['kind'])) {
            throw ValidationException::withMessages(['data.media_id' => $problem]);
        }

        return $data;
    }
}
