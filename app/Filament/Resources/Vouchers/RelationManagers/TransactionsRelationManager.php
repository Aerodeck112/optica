<?php

namespace App\Filament\Resources\Vouchers\RelationManagers;

use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Istoric';

    public const TYPES = ['issue' => 'Emitere', 'redeem' => 'Utilizare', 'refund' => 'Restituire', 'adjust' => 'Corecție', 'expire' => 'Expirare', 'cancel' => 'Anulare'];

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('Data')->dateTime('d.m.Y H:i', config('aw.timezone')),
            TextColumn::make('type')->label('Operațiune')->formatStateUsing(fn ($state) => self::TYPES[$state] ?? $state),
            TextColumn::make('amount_minor')->label('Sumă')->formatStateUsing(fn ($state) => Money::format((int) $state)),
            TextColumn::make('balance_after_minor')->label('Sold după')->formatStateUsing(fn ($state) => Money::format((int) $state)),
            TextColumn::make('note')->label('Notă')->placeholder('—'),
        ]);
    }
}
