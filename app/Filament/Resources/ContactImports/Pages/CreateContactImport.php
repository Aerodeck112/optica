<?php

namespace App\Filament\Resources\ContactImports\Pages;

use App\Filament\Resources\ContactImports\ContactImportResource;
use App\Modules\Marketing\Models\ContactImport;
use App\Modules\Marketing\Services\ContactImportService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CreateContactImport extends CreateRecord
{
    protected static string $resource = ContactImportResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $import = ContactImport::query()->create([
            'filename' => is_array($data['filename'] ?? null) ? reset($data['filename']) : ($data['filename'] ?? 'import.csv'),
            'source_description' => $data['source_description'],
            'permission_proof' => $data['permission_proof'],
            'channels' => $data['channels'],
            'status' => 'processing',
            'created_by' => auth()->id(),
        ]);
        $path = is_array($data['file']) ? reset($data['file']) : $data['file'];
        try {
            return app(ContactImportService::class)->run($import, Storage::disk('local')->path($path));
        } catch (\RuntimeException $e) {
            $import->forceFill(['status' => 'failed', 'errors' => [$e->getMessage()]])->save();
            throw ValidationException::withMessages(['data.file' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return ContactImportResource::getUrl('index');
    }
}
