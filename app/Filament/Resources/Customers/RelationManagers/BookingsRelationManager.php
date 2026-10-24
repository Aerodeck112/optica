<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Bookings\BookingResource;
use App\Modules\Booking\Models\Booking;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    protected static ?string $title = 'Rezervări';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('starts_at')->label('Data')->dateTime('d.m.Y H:i', config('aw.timezone'))->placeholder('Cerere fără dată'),
                TextColumn::make('code')->label('Cod')->fontFamily('mono'),
                TextColumn::make('package.name')->label('Pachet')->state(fn (Booking $record) => $record->package?->getTranslation('name', 'ro')),
                TextColumn::make('participants')->label('Pers.'),
                TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => Booking::STATUSES[$state] ?? $state)->color(fn ($state) => BookingResource::statusColor($state)),
                TextColumn::make('total_minor')->label('Total')->formatStateUsing(fn ($state) => Money::format($state))->placeholder('—'),
            ])
            ->recordUrl(fn (Booking $record) => BookingResource::getUrl('view', ['record' => $record]));
    }
}
