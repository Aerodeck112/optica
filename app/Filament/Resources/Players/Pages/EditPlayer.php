<?php

namespace App\Filament\Resources\Players\Pages;

use App\Filament\Resources\Players\PlayerResource;
use App\Modules\Arena\Models\Player;
use App\Modules\Arena\Services\NicknamePolicy;
use App\Modules\Arena\Services\PlayerException;
use App\Modules\Arena\Services\PlayerService;
use App\Modules\Audit\AuditLogger;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/** Editarea controlată: fiecare schimbare de identitate publică trece prin serviciu (validare, moderare, audit). */
class EditPlayer extends EditRecord
{
    protected static string $resource = PlayerResource::class;

    protected function getHeaderActions(): array
    {
        return [PlayerResource::approveAction()->record($this->getRecord()), PlayerResource::blockAction()->record($this->getRecord())];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['guardian'] = $this->getRecord()->display_consent_guardian;
        $data['private_name'] = $this->getRecord()->private_name;

        return $data;
    }

    /** @param  Player  $record */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $service = app(PlayerService::class);
        $user = auth()->id();
        try {
            if (filled($data['nickname'] ?? null) && NicknamePolicy::clean($data['nickname']) !== $record->nickname) {
                $service->changeNickname($record, $data['nickname'], 'staff', $user);
            }
            if (filled($data['avatar_key'] ?? null) && $data['avatar_key'] !== $record->avatar_key) {
                $service->changeAvatar($record, $data['avatar_key'], $user);
            }
            $record->forceFill(['is_minor' => (bool) ($data['is_minor'] ?? false)]);
            if (($data['display_consent'] ?? $record->display_consent) !== $record->display_consent || ($data['guardian'] ?? null) !== $record->display_consent_guardian) {
                $service->setDisplayConsent($record, $data['display_consent'], 'crm', $data['guardian'] ?? null, $user);
            }
            $changes = array_filter([
                'customer_id' => ($data['customer_id'] ?? null) != $record->customer_id ? $data['customer_id'] ?? null : null,
            ], fn ($v) => $v !== null);
            $record->forceFill(['customer_id' => $data['customer_id'] ?? null, 'private_name' => filled($data['private_name'] ?? null) ? trim($data['private_name']) : null])->save();
            app(AuditLogger::class)->log('player.updated', $record, $changes + ['is_minor' => $record->is_minor]);
        } catch (PlayerException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            throw new Halt;
        }

        return $record->refresh();
    }
}
