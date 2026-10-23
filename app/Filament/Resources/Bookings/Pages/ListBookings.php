<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Support\LocalTime;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Rezervare nouă')];
    }

    public function getTabs(): array
    {
        $today = LocalTime::now()->toDateString();

        return [
            'today' => Tab::make('Azi')->modifyQueryUsing(fn (Builder $query) => $query->onLocalDate($today)->whereNotIn('status', ['expired'])),
            'upcoming' => Tab::make('Următoare')->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '>=', now())),
            'requests' => Tab::make('Cereri')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'request')->reorder('created_at', 'desc')),
            'past' => Tab::make('Trecute')->modifyQueryUsing(fn (Builder $query) => $query->where('starts_at', '<', now())->reorder('starts_at', 'desc')),
            'all' => Tab::make('Toate'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'upcoming';
    }
}
