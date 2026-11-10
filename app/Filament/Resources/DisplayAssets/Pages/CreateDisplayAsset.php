<?php

namespace App\Filament\Resources\DisplayAssets\Pages;

use App\Filament\Resources\DisplayAssets\DisplayAssetResource;
use App\Modules\Cms\Models\Media;
use App\Modules\Display\Models\DisplayAsset;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateDisplayAsset extends CreateRecord
{
    protected static string $resource = DisplayAssetResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $media = ! empty($data['upload']) ? DisplayAssetResource::storeUpload($data) : Media::query()->findOrFail($data['media_id']);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['data.upload' => $e->getMessage()]);
        }
        if ($problem = DisplayAssetResource::assertMedia($media, $data['kind'])) {
            throw ValidationException::withMessages([empty($data['upload']) ? 'data.media_id' : 'data.upload' => $problem]);
        }
        if ($data['kind'] === 'video' && $media->duration_seconds && (int) $data['duration_seconds'] === 8) {
            $data['duration_seconds'] = min(600, (int) $media->duration_seconds);
        }

        return DisplayAsset::query()->create([
            'title' => $data['title'], 'media_id' => $media->id, 'kind' => $data['kind'], 'caption' => $data['caption'] ?? null,
            'fit' => $data['fit'], 'weight' => $data['weight'], 'duration_seconds' => $data['duration_seconds'], 'screens' => $data['screens'],
            'starts_at' => $data['starts_at'] ?? null, 'ends_at' => $data['ends_at'] ?? null, 'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by' => auth()->id(),
        ]);
    }
}
