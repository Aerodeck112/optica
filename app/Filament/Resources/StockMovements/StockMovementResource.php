<?php

namespace App\Filament\Resources\StockMovements;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Modules\Inventory\Models\StockMovement;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StockMovementResource extends Resource
{
    use ChecksPermissions;

    public const TYPES = ['receipt' => 'Recepție', 'consumption' => 'Consum', 'adjustment' => 'Ajustare', 'inventory' => 'Inventar', 'sale' => 'Vânzare', 'write_off' => 'Casare', 'transfer_in' => 'Transfer intrare', 'transfer_out' => 'Transfer ieșire'];

    protected static ?string $model = StockMovement::class;

    protected static string $viewPermission = 'inventory.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Stocuri';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $modelLabel = 'mișcare de stoc';

    protected static ?string $pluralModelLabel = 'Mișcări de stoc';

    protected static ?int $navigationSort = 6;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false; // istoricul nu se modifică; corecțiile sunt mișcări noi
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['item.unit', 'warehouse', 'creator']))
            ->columns([
                TextColumn::make('occurred_at')->label('Data')->dateTime('d.m.Y H:i', config('aw.timezone'))->sortable(),
                TextColumn::make('item.name')->label('Articol')->searchable(),
                TextColumn::make('warehouse.name')->label('Gestiune'),
                TextColumn::make('type')->label('Tip')->badge()->color('gray')->formatStateUsing(fn ($state) => self::TYPES[$state] ?? $state),
                TextColumn::make('quantity')->label('Cantitate')->alignEnd()
                    ->formatStateUsing(fn ($state, StockMovement $record) => ((float) $state > 0 ? '+' : '').StockLevelResource::formatQuantity($state).' '.$record->item?->unit?->symbol)
                    ->color(fn ($state) => (float) $state < 0 ? 'danger' : 'success'),
                TextColumn::make('note')->label('Notă')->limit(40)->placeholder('—'),
                TextColumn::make('creator.name')->label('De către')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('type')->label('Tip')->options(self::TYPES)->multiple(),
                SelectFilter::make('warehouse_id')->label('Gestiune')->relationship('warehouse', 'name'),
                SelectFilter::make('item_id')->label('Articol')->relationship('item', 'name')->searchable(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListStockMovements::route('/')];
    }
}
