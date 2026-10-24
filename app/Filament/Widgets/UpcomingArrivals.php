<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Bookings\BookingResource;
use App\Modules\Booking\Models\Booking;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class UpcomingArrivals extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = ['md' => 2, 'xl' => 2];

    protected static ?string $heading = 'Următoarele sosiri';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('bookings.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Booking::query()->with('package')->whereIn('status', ['pending', 'confirmed', 'checked_in'])->where('starts_at', '>=', now()->subHours(2))->orderBy('starts_at')->limit(8))
            ->paginated(false)
            ->columns([
                TextColumn::make('starts_at')->label('Când')->dateTime('D d.m, H:i', config('aw.timezone')),
                TextColumn::make('contact_name')->label('Client')->description(fn (Booking $record) => $record->package?->getTranslation('name', 'ro')),
                TextColumn::make('participants')->label('Pers.'),
                TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => Booking::STATUSES[$state] ?? $state)->color(fn ($state) => BookingResource::statusColor($state)),
            ])
            ->recordUrl(fn (Booking $record) => BookingResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('Nicio rezervare în următoarea perioadă');
    }
}
