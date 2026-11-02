<?php

namespace App\Filament\Resources\SocialPosts\Pages;

use App\Filament\Resources\SocialPosts\SocialPostResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSocialPost extends EditRecord
{
    protected static string $resource = SocialPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bundle')->label('Descarcă imaginile și textul')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->url(fn () => route('admin.social.bundle', $this->getRecord())),
            DeleteAction::make(),
        ];
    }
}
