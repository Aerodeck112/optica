<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Resources\Leads\LeadActions;
use App\Filament\Resources\Leads\LeadResource;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;

class ViewLead extends ViewRecord
{
    protected static string $resource = LeadResource::class;

    public function getTitle(): string
    {
        return 'Cererea '.$this->getRecord()->reference.' — '.$this->getRecord()->contact_name;
    }

    protected function getHeaderActions(): array
    {
        $actions = array_map(fn ($a) => $a->after(fn () => $this->getRecord()->refresh()), LeadActions::all());

        return [$actions[3], $actions[0], ActionGroup::make([$actions[1], $actions[2], $actions[4]])->label('Mai multe')->button()->color('gray')->icon('heroicon-o-ellipsis-vertical')];
    }
}
