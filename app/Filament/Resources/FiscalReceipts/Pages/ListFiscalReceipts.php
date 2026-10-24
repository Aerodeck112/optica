<?php

namespace App\Filament\Resources\FiscalReceipts\Pages;

use App\Filament\Resources\FiscalReceipts\FiscalReceiptResource;
use Filament\Resources\Pages\ListRecords;

class ListFiscalReceipts extends ListRecords
{
    protected static string $resource = FiscalReceiptResource::class;
}
