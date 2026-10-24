<?php

namespace App\Filament\Resources\PurchaseInvoices;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\PurchaseInvoice;
use App\Modules\Inventory\Models\PurchaseInvoiceLine;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryException;
use App\Modules\Inventory\Services\StockService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
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

class PurchaseInvoiceResource extends Resource
{
    use ChecksPermissions;

    public const STATUSES = ['registered' => 'Înregistrată', 'partially_received' => 'Recepționată parțial', 'received' => 'Recepționată', 'paid' => 'Achitată', 'cancelled' => 'Anulată'];

    public const LINE_TYPES = ['goods' => 'Marfă / consumabil', 'equipment' => 'Echipament', 'service' => 'Serviciu (nu intră în stoc)'];

    protected static ?string $model = PurchaseInvoice::class;

    protected static string $viewPermission = 'inventory.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Stocuri';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static ?string $modelLabel = 'factură de achiziție';

    protected static ?string $pluralModelLabel = 'Facturi furnizori';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'number';

    public static function canEdit(Model $record): bool
    {
        return static::userCan('inventory.manage') && ! $record->lines()->where('received_quantity', '>', 0)->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Factura')->columns(4)->columnSpanFull()->schema([
                Select::make('supplier_id')->label('Furnizor')->required()->searchable()->preload()->columnSpan(2)
                    ->options(fn () => Supplier::query()->orderBy('name')->pluck('name', 'id'))
                    ->disabledOn('edit'),
                TextInput::make('number')->label('Număr')->required()->maxLength(60)->disabledOn('edit'),
                TextInput::make('declared_total')->label('Total de plată (din factură)')->suffix('lei')
                    ->helperText('Opțional; dacă diferă de calculul pe linii (rotunjiri), se folosește valoarea furnizorului.')
                    ->rule('nullable')->rule('regex:/^\d{1,9}([.,]\d{1,2})?$/')->visibleOn('create'),
                DatePicker::make('issue_date')->label('Data facturii')->required()->native(false)->displayFormat('d.m.Y')->disabledOn('edit'),
                DatePicker::make('due_date')->label('Scadență')->native(false)->displayFormat('d.m.Y'),
            ]),
            Section::make('Linii (prețuri fără TVA)')->columnSpanFull()->schema([
                Repeater::make('lines')->hiddenLabel()->minItems(1)->defaultItems(1)->addActionLabel('Adaugă linie')
                    ->table([
                        TableColumn::make('Descriere')->markAsRequired(),
                        TableColumn::make('Articol din stoc')->width('220px'),
                        TableColumn::make('Tip')->width('170px'),
                        TableColumn::make('Cantitate')->markAsRequired()->width('110px'),
                        TableColumn::make('Preț unitar')->markAsRequired()->width('130px'),
                        TableColumn::make('TVA %')->width('90px'),
                    ])
                    ->schema([
                        TextInput::make('description')->required()->maxLength(255),
                        Select::make('item_id')->searchable()->options(fn () => Item::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))->placeholder('—'),
                        Select::make('line_type')->native()->options(self::LINE_TYPES)->default('goods')->selectablePlaceholder(false),
                        TextInput::make('quantity')->required()->numeric()->default(1),
                        TextInput::make('unit_price')->required()->numeric(),
                        TextInput::make('vat_rate')->numeric()->default(21),
                    ]),
            ]),
            Textarea::make('notes')->label('Note')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function linesToForm(PurchaseInvoice $invoice): array
    {
        return $invoice->lines->map(fn (PurchaseInvoiceLine $line) => [
            'description' => $line->description, 'item_id' => $line->item_id, 'line_type' => $line->line_type,
            'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.'), 'unit_price' => rtrim(rtrim((string) $line->unit_price, '0'), '.'), 'vat_rate' => rtrim(rtrim((string) $line->vat_rate, '0'), '.'),
        ])->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('issue_date', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('supplier'))
            ->columns([
                TextColumn::make('issue_date')->label('Data')->date('d.m.Y')->sortable(),
                TextColumn::make('supplier.name')->label('Furnizor')->searchable(),
                TextColumn::make('number')->label('Număr')->searchable(),
                TextColumn::make('gross_total_minor')->label('Total')->formatStateUsing(fn ($state) => Money::format($state))->alignEnd(),
                TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'received', 'paid' => 'success', 'partially_received' => 'warning', default => 'gray'
                    }),
                TextColumn::make('source')->label('Sursă')->formatStateUsing(fn ($state) => $state === 'efactura' ? 'e-Factura (SPV)' : 'Manual'),
            ])
            ->filters([SelectFilter::make('status')->label('Stare')->options(self::STATUSES)])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Factura')->columns(4)->columnSpanFull()->schema([
                TextEntry::make('supplier.name')->label('Furnizor'),
                TextEntry::make('number')->label('Număr'),
                TextEntry::make('issue_date')->label('Data')->date('d.m.Y'),
                TextEntry::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state),
                TextEntry::make('net_total_minor')->label('Fără TVA')->formatStateUsing(fn ($state) => Money::format($state)),
                TextEntry::make('vat_total_minor')->label('TVA')->formatStateUsing(fn ($state) => Money::format($state)),
                TextEntry::make('gross_total_minor')->label('Total')->formatStateUsing(fn ($state) => Money::format($state))->weight('bold'),
                TextEntry::make('due_date')->label('Scadență')->date('d.m.Y')->placeholder('—'),
            ]),
            Section::make('Linii')->columnSpanFull()->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->table([
                    RepeatableEntry\TableColumn::make('Descriere'),
                    RepeatableEntry\TableColumn::make('Articol'),
                    RepeatableEntry\TableColumn::make('Cant.'),
                    RepeatableEntry\TableColumn::make('Recepționat'),
                    RepeatableEntry\TableColumn::make('Preț unitar'),
                    RepeatableEntry\TableColumn::make('Valoare'),
                ])->schema([
                    TextEntry::make('description'),
                    TextEntry::make('item.name')->placeholder(fn (PurchaseInvoiceLine $record) => $record->line_type === 'service' ? 'serviciu' : 'neasociat'),
                    TextEntry::make('quantity')->formatStateUsing(fn ($state) => rtrim(rtrim((string) $state, '0'), '.')),
                    TextEntry::make('received_quantity')->formatStateUsing(fn ($state) => rtrim(rtrim((string) $state, '0'), '.')),
                    TextEntry::make('unit_price')->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.').' lei'),
                    TextEntry::make('line_net_minor')->formatStateUsing(fn ($state) => Money::format($state)),
                ]),
            ]),
        ]);
    }

    public static function receiveAction(): Action
    {
        return Action::make('receive')->label('Recepție (NIR)')->icon('heroicon-o-inbox-arrow-down')->color('success')
            ->visible(fn (PurchaseInvoice $record) => ! in_array($record->status, ['received', 'cancelled'], true))
            ->schema([
                Select::make('warehouse_id')->label('Gestiune')->required()->native()
                    ->options(fn () => Warehouse::query()->where('is_active', true)->pluck('name', 'id')),
            ])
            ->modalDescription('Se creează o ciornă de recepție cu liniile de marfă asociate articolelor și încă nerecepționate. Cantitățile pot fi corectate înainte de confirmare.')
            ->action(function (PurchaseInvoice $record, array $data, $livewire) {
                try {
                    $receipt = app(StockService::class)->proposeReceipt($record, (int) $data['warehouse_id'], auth()->id());
                    $livewire->redirect(GoodsReceiptResource::getUrl('edit', ['record' => $receipt]));
                } catch (InventoryException $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseInvoices::route('/'),
            'create' => Pages\CreatePurchaseInvoice::route('/create'),
            'view' => Pages\ViewPurchaseInvoice::route('/{record}'),
            'edit' => Pages\EditPurchaseInvoice::route('/{record}/edit'),
        ];
    }
}
