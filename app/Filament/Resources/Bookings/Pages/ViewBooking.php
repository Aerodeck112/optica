<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingActions;
use App\Filament\Resources\Bookings\BookingResource;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    public function getTitle(): string
    {
        return 'Rezervarea '.$this->getRecord()->code;
    }

    protected function getHeaderActions(): array
    {
        $refresh = fn () => $this->record = $this->getRecord()->refresh()->load(['package', 'game', 'customer', 'items', 'events', 'occupancies.resource']);
        $actions = array_map(fn ($action) => $action->after($refresh), BookingActions::all());

        return [
            ...array_slice($actions, 0, 4),
            ActionGroup::make(array_slice($actions, 4))->label('Mai multe')->button()->color('gray')->icon('heroicon-o-ellipsis-vertical'),
        ];
    }

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['package', 'game', 'customer', 'items', 'events', 'occupancies.resource']);
    }
}
