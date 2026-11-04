<?php

namespace App\Filament\Resources\PlayerWaivers\Pages;

use App\Filament\Resources\PlayerWaivers\PlayerWaiverResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListPlayerWaivers extends ListRecords
{
    protected static string $resource = PlayerWaiverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('qr')->label('Cod QR pentru recepție')->icon('heroicon-o-qr-code')->color('gray')
                ->url(route('admin.waivers.qr'))->openUrlInNewTab(),
        ];
    }
}
