<?php

namespace App\Filament\Resources\SocialPosts\Pages;

use App\Filament\Resources\SocialPosts\SocialPostResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSocialPost extends CreateRecord
{
    protected static string $resource = SocialPostResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['created_by' => auth()->id(), 'source' => 'manual'];
    }
}
