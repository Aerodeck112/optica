<?php

namespace App\Filament\Resources\Items;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Support\Fields;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Unit;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ItemResource extends Resource
{
    use ChecksPermissions;

    public const TYPES = ['equipment' => 'Echipament', 'consumable' => 'Consumabil', 'merchandise' => 'Marfă', 'service' => 'Serviciu'];

    protected static ?string $model = Item::class;

    protected static string $viewPermission = 'inventory.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Stocuri';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $modelLabel = 'articol';

    protected static ?string $pluralModelLabel = 'Articole';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Denumire')->required()->maxLength(190),
            TextInput::make('sku')->label('Cod intern')->required()->maxLength(60)->unique(ignoreRecord: true),
            Select::make('type')->label('Tip')->options(self::TYPES)->required()->native()->live()
                ->afterStateUpdated(fn ($state, $set) => $set('track_stock', $state !== 'service')),
            Select::make('unit_id')->label('Unitate de măsură')->options(fn () => Unit::query()->orderBy('name')->get()->mapWithKeys(fn (Unit $unit) => [$unit->id => "{$unit->name} ({$unit->symbol})"]))->required()->native(),
            Select::make('vat_group')->label('TVA la vânzare')->options(PackageResource::VAT_GROUPS)->default('standard')->native(),
            Fields::money('sale_price_minor', 'Preț de vânzare (cu TVA)')->helperText('Doar pentru mărfurile vândute la recepție. Gol = nu se vinde.'),
            Toggle::make('track_stock')->label('Urmărește stocul')->default(true),
            TextInput::make('min_stock')->label('Stoc minim (alertă)')->numeric(),
            TextInput::make('serial_number')->label('Serie echipament')->maxLength(120)->visible(fn ($get) => $get('type') === 'equipment'),
            Toggle::make('is_active')->label('Activ')->default(true),
            Textarea::make('notes')->label('Note')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('unit')->withSum('stockLevels as stock_total', 'quantity'))
            ->columns([
                TextColumn::make('name')->label('Denumire')->searchable()->sortable(),
                TextColumn::make('sku')->label('Cod')->fontFamily('mono')->searchable(),
                TextColumn::make('type')->label('Tip')->formatStateUsing(fn ($state) => self::TYPES[$state] ?? $state),
                TextColumn::make('stock_total')->label('Stoc')->formatStateUsing(fn ($state, Item $record) => $record->track_stock ? rtrim(rtrim(number_format((float) $state, 3, ',', '.'), '0'), ',').' '.$record->unit?->symbol : '—')
                    ->color(fn ($state, Item $record) => $record->min_stock !== null && (float) $state < (float) $record->min_stock ? 'danger' : null)
                    ->description(fn ($state, Item $record) => $record->min_stock !== null && (float) $state < (float) $record->min_stock ? 'sub stocul minim' : null),
                IconColumn::make('is_active')->label('Activ')->boolean(),
            ])
            ->filters([SelectFilter::make('type')->label('Tip')->options(self::TYPES)])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageItems::route('/')];
    }
}
