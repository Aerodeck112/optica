<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ConsentEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'consentEvents';

    protected static ?string $title = 'Istoric consimțăminte';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Data')->dateTime('d.m.Y H:i', config('aw.timezone')),
                TextColumn::make('channel')->label('Canal')->formatStateUsing(fn ($state) => $state === 'sms' ? 'SMS' : 'E-mail'),
                TextColumn::make('action')->label('Acțiune')->badge()->formatStateUsing(fn ($state) => $state === 'granted' ? 'Acordat' : 'Retras')->color(fn ($state) => $state === 'granted' ? 'success' : 'gray'),
                TextColumn::make('source')->label('Sursă'),
                TextColumn::make('actor_type')->label('Înregistrat de')->formatStateUsing(fn ($state) => ['customer' => 'Client', 'user' => 'Personal', 'system' => 'Sistem'][$state] ?? $state),
            ]);
    }
}
