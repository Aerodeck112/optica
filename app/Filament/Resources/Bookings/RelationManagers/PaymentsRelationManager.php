<?php

namespace App\Filament\Resources\Bookings\RelationManagers;

use App\Filament\Resources\Payments\PaymentActions;
use App\Filament\Resources\Payments\PaymentResource;
use App\Modules\Finance\Models\Payment;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Încasări';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('finance.view') || (bool) auth()->user()?->can('finance.issue');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Data')->dateTime('d.m.Y H:i', config('aw.timezone')),
                TextColumn::make('method')->label('Metodă')->formatStateUsing(fn ($state) => Payment::METHODS[$state] ?? $state),
                TextColumn::make('amount_minor')->label('Sumă')->formatStateUsing(fn ($state) => Money::format($state)),
                TextColumn::make('refunded_minor')->label('Rambursat')->formatStateUsing(fn ($state) => $state ? Money::format($state) : '—'),
                TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => Payment::STATUSES[$state] ?? $state)->color(fn ($state) => PaymentResource::statusColor($state)),
                TextColumn::make('recorder.name')->label('Înregistrat de')->placeholder('Online'),
                TextColumn::make('note')->label('Notă')->limit(40)->placeholder('—'),
            ])
            ->recordActions([PaymentActions::fiscalReceipt(), PaymentActions::requestRefund()])
            ->emptyStateHeading('Nicio încasare')
            ->emptyStateDescription('Încasările se înregistrează din butonul „Încasează” al rezervării sau sosesc automat de la plata online.');
    }
}
