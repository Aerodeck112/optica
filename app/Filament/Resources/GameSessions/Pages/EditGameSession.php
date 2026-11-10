<?php

namespace App\Filament\Resources\GameSessions\Pages;

use App\Filament\Resources\GameSessions\GameSessionResource;
use App\Modules\Audit\AuditLogger;
use App\Modules\Display\Services\DisplayVersion;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/** Detaliile sesiunii (joc, arenă, regula de clasament, test). Starea se schimbă doar din acțiunile fișei. */
class EditGameSession extends EditRecord
{
    protected static string $resource = GameSessionResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $allowed = array_intersect_key($data, array_flip(['resource_id', 'game_id', 'booking_id', 'scheduled_at', 'result_type', 'is_test', 'notes']));
        if ($record->isRunning()) {
            unset($allowed['resource_id']);
        }
        $before = $record->only(array_keys($allowed));
        $record->forceFill($allowed)->save();
        app(AuditLogger::class)->log('game_session.updated', $record, ['before' => $before, 'after' => $record->only(array_keys($allowed))]);
        DisplayVersion::bump();

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return GameSessionResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
