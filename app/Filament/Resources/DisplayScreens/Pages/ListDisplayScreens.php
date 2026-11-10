<?php

namespace App\Filament\Resources\DisplayScreens\Pages;

use App\Filament\Resources\DisplayScreens\DisplayScreenResource;
use App\Modules\Arena\Models\GameSession;
use App\Modules\Arena\Models\Player;
use App\Modules\Arena\Models\SessionParticipant;
use App\Modules\Audit\AuditLogger;
use App\Modules\Display\Services\DisplayVersion;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListDisplayScreens extends ListRecords
{
    protected static string $resource = DisplayScreenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('removeDemo')->label('Șterge jucătorii de prezentare')->icon('heroicon-o-trash')->color('gray')
                ->visible(fn () => auth()->user()?->can('display.manage') && Player::query()->where('is_demo', true)->exists())
                ->requiresConfirmation()
                ->modalDescription('Se șterg definitiv cei 5 jucători de prezentare și sesiunile lor. Jucătorii și sesiunile reale nu sunt afectate.')
                ->action(function () {
                    $sessions = GameSession::query()->where('is_demo', true)->pluck('id');
                    SessionParticipant::query()->whereIn('game_session_id', $sessions)->delete();
                    GameSession::query()->whereIn('id', $sessions)->delete();
                    $players = Player::query()->where('is_demo', true)->whereDoesntHave('participations')->delete();
                    app(AuditLogger::class)->log('display.demo_removed', null, ['players' => $players, 'sessions' => $sessions->count()]);
                    DisplayVersion::bump();
                    Notification::make()->success()->title('Jucătorii de prezentare au fost șterși.')->send();
                }),
            CreateAction::make()->label('Ecran nou'),
        ];
    }
}
