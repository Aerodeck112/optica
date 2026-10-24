<?php

namespace App\Filament\Resources\GoodsReceipts;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\PurchaseInvoices\PurchaseInvoiceResource;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\GoodsReceiptLine;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryException;
use App\Modules\Inventory\Services\StockService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GoodsReceiptResource extends Resource
{
    use ChecksPermissions;

    public const STATUSES = ['draft' => 'Ciornă', 'confirmed' => 'Confirmată', 'cancelled' => 'Anulată'];

    protected static ?string $model = GoodsReceipt::class;

    protected static string $viewPermission = 'inventory.manage';

    protected static ?string $deletePermission = 'inventory.manage';

    protected static string|\UnitEnum|null $navigationGroup = 'Stocuri';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $modelLabel = 'recepție (NIR)';

    protected static ?string $pluralModelLabel = 'Recepții (NIR)';

    protected static ?int $navigationSort = 2;

    public static function canEdit(Model $record): bool
    {
        return $record->status === 'draft' && static::userCan('inventory.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return $record->status === 'draft' && static::userCan('inventory.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Recepție')->columns(3)->columnSpanFull()->schema([
                Select::make('warehouse_id')->label('Gestiune')->required()->native()->options(fn () => Warehouse::query()->where('is_active', true)->pluck('name', 'id')),
                Select::make('supplier_id')->label('Furnizor')->searchable()->options(fn () => Supplier::query()->orderBy('name')->pluck('name', 'id'))
                    ->disabled(fn (?GoodsReceipt $record) => (bool) $record?->purchase_invoice_id),
                DatePicker::make('received_on')->label('Data recepției')->native(false)->displayFormat('d.m.Y')->default(now(config('aw.timezone'))->toDateString()),
            ]),
            Section::make('Articole recepționate')->columnSpanFull()->schema([
                Repeater::make('lines')->hiddenLabel()->minItems(1)->defaultItems(1)->addActionLabel('Adaugă articol')
                    ->table([
                        TableColumn::make('Articol')->markAsRequired(),
                        TableColumn::make('Cantitate')->markAsRequired()->width('140px'),
                        TableColumn::make('Cost unitar (fără TVA)')->width('200px'),
                    ])
                    ->schema([
                        Select::make('item_id')->required()->searchable()->options(fn () => Item::query()->where('is_active', true)->where('track_stock', true)->orderBy('name')->pluck('name', 'id')),
                        TextInput::make('quantity')->required()->numeric()->minValue(0.001),
                        TextInput::make('unit_cost')->numeric()->default(0)->suffix('lei'),
                        Hidden::make('purchase_invoice_line_id'),
                    ]),
            ]),
            Textarea::make('notes')->label('Observații (diferențe, deteriorări)')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function linesToForm(GoodsReceipt $receipt): array
    {
        return $receipt->lines->map(fn (GoodsReceiptLine $line) => [
            'item_id' => $line->item_id, 'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.'),
            'unit_cost' => rtrim(rtrim((string) $line->unit_cost, '0'), '.'), 'purchase_invoice_line_id' => $line->purchase_invoice_line_id,
        ])->all();
    }

    public static function saveLines(GoodsReceipt $receipt, array $lines): void
    {
        $receipt->lines()->delete();
        foreach ($lines as $line) {
            $receipt->lines()->create([
                'item_id' => (int) $line['item_id'],
                'quantity' => str_replace(',', '.', (string) $line['quantity']),
                'unit_cost' => str_replace(',', '.', (string) ($line['unit_cost'] ?? '0')) ?: '0',
                'purchase_invoice_line_id' => ($line['purchase_invoice_line_id'] ?? null) ?: null,
            ]);
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['warehouse', 'supplier', 'purchaseInvoice']))
            ->columns([
                TextColumn::make('number')->label('Număr')->fontFamily('mono')->placeholder('Ciornă'),
                TextColumn::make('received_on')->label('Data')->date('d.m.Y')->placeholder('—'),
                TextColumn::make('warehouse.name')->label('Gestiune'),
                TextColumn::make('supplier.name')->label('Furnizor')->placeholder('—'),
                TextColumn::make('purchaseInvoice.number')->label('Factura')->placeholder('—')
                    ->url(fn (GoodsReceipt $record) => $record->purchase_invoice_id ? PurchaseInvoiceResource::getUrl('view', ['record' => $record->purchase_invoice_id]) : null),
                TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'confirmed' => 'success', 'cancelled' => 'danger', default => 'warning'
                    }),
            ])
            ->filters([SelectFilter::make('status')->label('Stare')->options(self::STATUSES)])
            ->recordActions([ViewAction::make(), EditAction::make()->visible(fn (GoodsReceipt $record) => self::canEdit($record))]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Recepție')->columns(4)->columnSpanFull()->schema([
                TextEntry::make('number')->label('Număr')->placeholder('Ciornă'),
                TextEntry::make('received_on')->label('Data')->date('d.m.Y')->placeholder('—'),
                TextEntry::make('warehouse.name')->label('Gestiune'),
                TextEntry::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state),
                TextEntry::make('supplier.name')->label('Furnizor')->placeholder('—'),
                TextEntry::make('purchaseInvoice.number')->label('Factura furnizorului')->placeholder('—'),
                TextEntry::make('notes')->label('Observații')->placeholder('—')->columnSpan(2),
            ]),
            Section::make('Articole')->columnSpanFull()->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->table([
                    RepeatableEntry\TableColumn::make('Articol'),
                    RepeatableEntry\TableColumn::make('Cantitate'),
                    RepeatableEntry\TableColumn::make('Cost unitar'),
                ])->schema([
                    TextEntry::make('item.name'),
                    TextEntry::make('quantity')->formatStateUsing(fn ($state, GoodsReceiptLine $record) => rtrim(rtrim((string) $state, '0'), '.').' '.$record->item?->unit?->symbol),
                    TextEntry::make('unit_cost')->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.').' lei'),
                ]),
            ]),
        ]);
    }

    public static function confirmAction(): Action
    {
        return Action::make('confirm')->label('Confirmă recepția')->icon('heroicon-o-check-badge')->color('success')
            ->visible(fn (GoodsReceipt $record) => $record->status === 'draft')
            ->requiresConfirmation()
            ->modalDescription('Se alocă numărul NIR și cantitățile intră în stoc. După confirmare, recepția se poate doar anula (cu mișcări inverse).')
            ->action(fn (GoodsReceipt $record) => self::run(fn () => app(StockService::class)->confirmReceipt($record, auth()->id()), 'Recepția a fost confirmată.'));
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')->label('Anulează')->icon('heroicon-o-x-circle')->color('danger')
            ->visible(fn (GoodsReceipt $record) => $record->status === 'confirmed')
            ->schema([TextInput::make('reason')->label('Motiv')->required()->maxLength(200)])
            ->action(fn (GoodsReceipt $record, array $data) => self::run(fn () => app(StockService::class)->cancelReceipt($record, auth()->id(), $data['reason']), 'Recepția a fost anulată.'));
    }

    public static function printAction(): Action
    {
        return Action::make('print')->label('Tipărește NIR')->icon('heroicon-o-printer')->color('gray')
            ->visible(fn (GoodsReceipt $record) => $record->status !== 'draft')
            ->url(fn (GoodsReceipt $record) => route('admin.goods-receipts.print', $record), shouldOpenInNewTab: true);
    }

    private static function run(\Closure $callback, string $success): void
    {
        try {
            $callback();
            Notification::make()->success()->title($success)->send();
        } catch (InventoryException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGoodsReceipts::route('/'),
            'create' => Pages\CreateGoodsReceipt::route('/create'),
            'view' => Pages\ViewGoodsReceipt::route('/{record}'),
            'edit' => Pages\EditGoodsReceipt::route('/{record}/edit'),
        ];
    }
}
