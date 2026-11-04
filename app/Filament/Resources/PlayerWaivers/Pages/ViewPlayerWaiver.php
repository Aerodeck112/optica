<?php

namespace App\Filament\Resources\PlayerWaivers\Pages;

use App\Filament\Resources\PlayerWaivers\PlayerWaiverResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewPlayerWaiver extends ViewRecord
{
    protected static string $resource = PlayerWaiverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PlayerWaiverResource::verifyAction()->record($this->getRecord()),
            Action::make('print')->label('Tipărește')->icon('heroicon-o-printer')->color('gray')
                ->url(fn () => route('admin.waivers.print', $this->getRecord()))->openUrlInNewTab(),
        ];
    }
}
