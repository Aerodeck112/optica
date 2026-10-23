<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Resources\Pages\PageResource;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Services\PageEditor;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePage extends CreateRecord
{
    protected static string $resource = PageResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $page = Page::query()->create([
            'key' => $data['key'],
            'template' => 'blocks',
            'slug' => [],
            'title' => array_filter((array) ($data['title'] ?? [])),
            'blocks' => [],
            'published_locales' => ['ro'],
            'status' => 'draft',
            'og_image_id' => $data['og_image_id'] ?? null,
            'noindex' => (bool) ($data['noindex'] ?? false),
            'is_system' => false,
            'sort' => (int) Page::query()->max('sort') + 1,
        ]);

        return app(PageEditor::class)->saveDraft($page, $data, auth()->id(), 'Creare pagină');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
