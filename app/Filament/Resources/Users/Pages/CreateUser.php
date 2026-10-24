<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Modules\Audit\AuditLogger;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    private bool $sendLink = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['password'])) {
            // parolă aleatorie, necunoscută nimănui; utilizatorul o setează prin link
            $data['password'] = Str::password(32);
            $this->sendLink = true;
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        app(AuditLogger::class)->log('user.created', $this->getRecord(), ['roles' => $this->getRecord()->getRoleNames()->all()]);
        if ($this->sendLink) {
            Password::broker()->sendResetLink(['email' => $this->getRecord()->email]);
        }
    }
}
