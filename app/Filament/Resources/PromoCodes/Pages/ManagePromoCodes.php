<?php

namespace App\Filament\Resources\PromoCodes\Pages;

use App\Filament\Resources\PromoCodes\PromoCodeResource;
use App\Modules\Audit\AuditLogger;
use App\Modules\Marketing\Models\PromoCode;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePromoCodes extends ManageRecords
{
    protected static string $resource = PromoCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(fn (array $data) => $data + ['created_by' => auth()->id()])
                ->after(fn (PromoCode $record) => app(AuditLogger::class)->log('promo_code.created', $record, ['code' => $record->code])),
        ];
    }
}
