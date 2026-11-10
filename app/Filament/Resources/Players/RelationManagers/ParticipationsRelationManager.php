<?php

namespace App\Filament\Resources\Players\RelationManagers;

use App\Filament\Resources\GameSessions\GameSessionResource;
use App\Modules\Arena\Models\GameSession;
use App\Modules\Arena\Models\SessionParticipant;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Istoricul jucătorului (legat de id, nu de nickname): rămâne neschimbat la redenumire. */
class ParticipationsRelationManager extends RelationManager
{
    protected static string $relationship = 'participations';

    protected static ?string $title = 'Sesiuni jucate';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query) => $query->with('session.game'))->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('session.started_at')->label('Data')->dateTime('d.m.Y H:i', config('aw.timezone'))->placeholder('Neîncepută'),
                TextColumn::make('session.game.title')->label('Joc')->formatStateUsing(fn ($state, SessionParticipant $r) => $r->session->game?->getTranslation('title', 'ro'))->placeholder('—')
                    ->url(fn (SessionParticipant $r) => GameSessionResource::getUrl('view', ['record' => $r->game_session_id])),
                TextColumn::make('session.status')->label('Sesiune')->badge()->formatStateUsing(fn ($state) => GameSession::STATUSES[$state] ?? $state),
                TextColumn::make('status')->label('Participare')->badge()->formatStateUsing(fn ($state) => SessionParticipant::STATUSES[$state] ?? $state),
                IconColumn::make('counts')->label('În clasament')->boolean(),
                TextColumn::make('place')->label('Loc')->placeholder('—'),
                TextColumn::make('correction_note')->label('Corecție')->placeholder('—')->wrap(),
            ]);
    }
}
