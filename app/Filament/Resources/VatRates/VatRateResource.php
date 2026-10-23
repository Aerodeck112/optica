<?php

namespace App\Filament\Resources\VatRates;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Packages\PackageResource;
use App\Modules\Catalog\Models\VatRate;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Cotele de TVA cu perioadă de valabilitate. Documentele emise păstrează cota din momentul emiterii (snapshot),
 * deci modificarea unei cote nu schimbă facturile deja emise.
 */
class VatRateResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = VatRate::class;

    protected static string $viewPermission = 'finance.view';

    protected static ?string $managePermission = 'efactura.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Financiar';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $modelLabel = 'cotă TVA';

    protected static ?string $pluralModelLabel = 'Cote TVA';

    protected static ?int $navigationSort = 90;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                TextInput::make('name')->label('Denumire')->required()->maxLength(80),
                Select::make('group')->label('Grupă')->options(PackageResource::VAT_GROUPS)->required(),
                TextInput::make('rate')->label('Cotă')->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%')->required(),
                Select::make('category_code')->label('Cod categorie (UBL)')->options(['S' => 'S — standard', 'Z' => 'Z — cota zero', 'E' => 'E — scutit', 'AE' => 'AE — taxare inversă', 'K' => 'K — livrare intracomunitară', 'O' => 'O — în afara sferei TVA'])->required(),
                DatePicker::make('valid_from')->label('Valabil de la')->required(),
                DatePicker::make('valid_until')->label('Valabil până la')->afterOrEqual('valid_from'),
                TextInput::make('exemption_code')->label('Cod scutire (VATEX)')->maxLength(30),
                TextInput::make('exemption_reason')->label('Motiv scutire')->maxLength(255),
                Toggle::make('needs_accountant_review')->label('Necesită verificarea contabilului')->inline(false),
            ]),
            Textarea::make('notes')->label('Note (temei legal)')->rows(2),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('valid_from', 'desc')
            ->columns([
                TextColumn::make('name')->label('Denumire'),
                TextColumn::make('group')->label('Grupă')->formatStateUsing(fn ($state) => PackageResource::VAT_GROUPS[$state] ?? $state),
                TextColumn::make('rate')->label('Cotă')->suffix('%'),
                TextColumn::make('category_code')->label('Cod'),
                TextColumn::make('valid_from')->label('De la')->date('d.m.Y'),
                TextColumn::make('valid_until')->label('Până la')->date('d.m.Y')->placeholder('—'),
                IconColumn::make('needs_accountant_review')->label('De verificat')->boolean()->trueIcon('heroicon-o-exclamation-triangle')->trueColor('warning')->falseIcon('heroicon-o-check'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageVatRates::route('/')];
    }
}
