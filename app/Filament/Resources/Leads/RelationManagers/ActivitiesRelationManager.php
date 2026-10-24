<?php

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Modules\Crm\Models\Lead;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Istoric';

    public const TYPES = ['stage_change' => 'Etapă', 'call' => 'Apel', 'email' => 'E-mail', 'meeting' => 'Întâlnire', 'note' => 'Notă', 'offer' => 'Ofertă'];

    public function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('created_at')->label('Data')->dateTime('d.m.Y H:i', config('aw.timezone')),
            TextColumn::make('type')->label('Tip')->formatStateUsing(fn ($state) => self::TYPES[$state] ?? $state),
            TextColumn::make('summary')->label('Detalii')->wrap()->state(function ($record) {
                if ($record->type === 'stage_change') {
                    return (Lead::STAGES[$record->data['from'] ?? ''] ?? '—').' → '.(Lead::STAGES[$record->data['to'] ?? ''] ?? '—').($record->body ? ': '.$record->body : '');
                }

                return $record->body;
            }),
            TextColumn::make('user.name')->label('De către')->placeholder('Client / sistem'),
        ]);
    }
}
