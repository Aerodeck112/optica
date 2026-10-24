<?php

namespace App\Filament\Resources\StockLevels;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryException;
use App\Modules\Inventory\Services\StockService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StockLevelResource extends Resource
{
    use ChecksPermissions;

    public const MOVEMENT_TYPES = ['consumption' => 'Consum', 'write_off' => 'Casare / deteriorare', 'sale' => 'Vânzare', 'adjustment_in' => 'Ajustare (+)', 'adjustment_out' => 'Ajustare (−)'];

    protected static ?string $model = StockLevel::class;

    protected static string $viewPermission = 'inventory.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Stocuri';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $modelLabel = 'stoc';

    protected static ?string $pluralModelLabel = 'Stoc curent';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function formatQuantity($quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 3, ',', '.'), '0'), ',');
    }

    public static function movementAction(): Action
    {
        return Action::make('movement')->label('Mișcare')->icon('heroicon-o-arrows-right-left')->color('gray')
            ->fillForm(fn (?StockLevel $record) => $record ? ['item_id' => $record->item_id, 'warehouse_id' => $record->warehouse_id] : [])
            ->schema([
                Select::make('item_id')->label('Articol')->required()->searchable()->options(fn () => Item::query()->where('track_stock', true)->where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                Select::make('warehouse_id')->label('Gestiune')->required()->native()->options(fn () => Warehouse::query()->where('is_active', true)->pluck('name', 'id')),
                Select::make('type')->label('Tip')->required()->native()->options(self::MOVEMENT_TYPES)->default('consumption'),
                TextInput::make('quantity')->label('Cantitate')->required()->numeric()->minValue(0.001),
                TextInput::make('note')->label('Motiv / notă')->maxLength(255)->required(fn ($get) => in_array($get('type'), ['write_off', 'adjustment_in', 'adjustment_out'], true)),
            ])
            ->action(function (array $data) {
                try {
                    app(StockService::class)->record(Item::query()->findOrFail($data['item_id']), (int) $data['warehouse_id'], (string) $data['quantity'], $data['type'], auth()->id(), $data['note'] ?? null);
                    Notification::make()->success()->title('Mișcarea a fost înregistrată.')->send();
                } catch (InventoryException $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['item.unit', 'warehouse'])->whereHas('item', fn ($item) => $item->where('track_stock', true)))
            ->defaultSort('item_id')
            ->columns([
                TextColumn::make('item.name')->label('Articol')->searchable(),
                TextColumn::make('item.sku')->label('Cod')->fontFamily('mono'),
                TextColumn::make('warehouse.name')->label('Gestiune'),
                TextColumn::make('quantity')->label('Cantitate')->alignEnd()
                    ->formatStateUsing(fn ($state, StockLevel $record) => self::formatQuantity($state).' '.$record->item?->unit?->symbol)
                    ->color(fn ($state, StockLevel $record) => $record->item?->min_stock !== null && (float) $state < (float) $record->item->min_stock ? 'danger' : null),
                TextColumn::make('item.min_stock')->label('Minim')->formatStateUsing(fn ($state) => $state === null ? '—' : self::formatQuantity($state))->alignEnd(),
                TextColumn::make('updated_at')->label('Ultima mișcare')->since(config('aw.timezone')),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')->label('Gestiune')->relationship('warehouse', 'name'),
                Filter::make('low')->label('Sub stocul minim')->query(fn (Builder $query) => $query->whereHas('item', fn ($item) => $item->whereNotNull('min_stock')->whereColumn('stock_levels.quantity', '<', 'items.min_stock'))),
            ])
            ->headerActions([self::movementAction()])
            ->recordActions([self::movementAction()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListStockLevels::route('/')];
    }
}
