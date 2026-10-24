<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Modules\Audit\AuditLogger;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function afterSave(): void
    {
        app(AuditLogger::class)->log('user.updated', $this->getRecord(), ['roles' => $this->getRecord()->getRoleNames()->all(), 'is_active' => $this->getRecord()->is_active]);
        if (! $this->getRecord()->is_active) {
            DB::table('sessions')->where('user_id', $this->getRecord()->id)->delete();
        }
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
