<?php

namespace App\Filament\Resources\DocumentSeries\Pages;

use App\Filament\Resources\DocumentSeries\DocumentSeriesResource;
use App\Modules\Finance\Models\DocumentSeries;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDocumentSeries extends ManageRecords
{
    protected static string $resource = DocumentSeriesResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->after(fn (DocumentSeries $record) => self::keepSingleDefault($record))];
    }

    /** O singură serie implicită pe tip de document. */
    public static function keepSingleDefault(DocumentSeries $record): void
    {
        if ($record->is_default) {
            DocumentSeries::query()->where('document_type', $record->document_type)->whereKeyNot($record->id)->update(['is_default' => false]);
        }
    }
}
