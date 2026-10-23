<?php

namespace App\Filament\Resources\Media\Pages;

use App\Filament\Resources\Media\MediaResource;
use App\Modules\Cms\Services\MediaProcessor;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class CreateMedia extends CreateRecord
{
    protected static string $resource = MediaResource::class;

    protected static ?string $title = 'Încarcă fișier';

    protected function handleRecordCreation(array $data): Model
    {
        $path = is_array($data['upload']) ? reset($data['upload']) : $data['upload'];
        $original = is_array($data['original_name'] ?? null) ? (string) reset($data['original_name']) : (string) ($data['original_name'] ?? basename($path));
        $attributes = Arr::only($data, ['alt', 'caption', 'category', 'rights_status', 'source', 'credit', 'rights_notes', 'depicts_minors', 'guardian_consent_confirmed']);
        if (! empty($attributes['guardian_consent_confirmed'])) {
            $attributes['guardian_consent_confirmed_by'] = auth()->id();
        }

        try {
            return app(MediaProcessor::class)->createFromStoredFile($path, $original, $attributes, auth()->id());
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['data.upload' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
