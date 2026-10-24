<?php

namespace App\Filament\Resources\InventoryCounts\RelationManagers;

use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Modules\Inventory\Models\InventoryCountLine;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Articole';

    public function isReadOnly(): bool
    {
        return $this->getOwnerRecord()->status !== 'draft';
    }

    public function table(Table $table): Table
    {
        $draft = $this->getOwnerRecord()->status === 'draft';

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('item.unit'))
            ->paginated([50, 100, 'all'])
            ->columns([
                TextColumn::make('item.name')->label('Articol')->searchable(),
                TextColumn::make('expected_quantity')->label($draft ? 'Scriptic (la pornire)' : 'Scriptic (la confirmare)')
                    ->formatStateUsing(fn ($state, InventoryCountLine $record) => StockLevelResource::formatQuantity($state).' '.$record->item?->unit?->symbol)->alignEnd(),
                $draft
                    ? TextInputColumn::make('counted_quantity')->label('Numărat')->type('number')->rules(['nullable', 'numeric', 'min:0'])
                    : TextColumn::make('counted_quantity')->label('Numărat')->placeholder('nenumărat')->formatStateUsing(fn ($state) => StockLevelResource::formatQuantity($state))->alignEnd(),
                TextColumn::make('difference')->label('Diferență')->alignEnd()
                    ->state(fn (InventoryCountLine $record) => $record->counted_quantity === null ? null : (float) $record->counted_quantity - (float) $record->expected_quantity)
                    ->formatStateUsing(fn ($state) => ($state > 0 ? '+' : '').StockLevelResource::formatQuantity($state))
                    ->color(fn ($state) => $state < 0 ? 'danger' : ($state > 0 ? 'success' : null))->placeholder('—'),
            ]);
    }
}
