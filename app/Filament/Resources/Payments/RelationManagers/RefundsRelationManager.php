<?php

namespace App\Filament\Resources\Payments\RelationManagers;

use App\Filament\Resources\Payments\PaymentActions;
use App\Filament\Resources\Refunds\RefundResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class RefundsRelationManager extends RelationManager
{
    protected static string $relationship = 'refunds';

    protected static ?string $title = 'Rambursări';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns(RefundResource::columns(withPayment: false))
            ->recordActions([PaymentActions::approveRefund(), PaymentActions::rejectRefund()]);
    }
}
