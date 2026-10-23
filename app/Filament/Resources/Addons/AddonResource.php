<?php

namespace App\Filament\Resources\Addons;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Support\Fields;
use App\Modules\Catalog\Models\Addon;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class AddonResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Addon::class;

    protected static string $viewPermission = 'catalog.manage';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?string $modelLabel = 'extra-opțiune';

    protected static ?string $pluralModelLabel = 'Extra-opțiuni';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Fields::localeTabs(fn (string $l, bool $d) => [
                TextInput::make("name.{$l}")->label('Nume')->required($d)->maxLength(120),
                Textarea::make("description.{$l}")->label('Descriere')->rows(2)->maxLength(500),
            ], 'addon'),
            Grid::make(3)->schema([
                Select::make('pricing_model')->label('Mod de calcul')->options(['flat' => 'Per rezervare', 'per_person' => 'Per participant'])->required()->default('flat'),
                Fields::money('price_minor', 'Preț (cu TVA)')->disabled(fn () => ! auth()->user()?->can('prices.manage')),
                Select::make('vat_group')->label('TVA')->options(PackageResource::VAT_GROUPS)->required()->default('standard'),
                TextInput::make('extra_minutes')->label('Prelungește rezervarea cu')->numeric()->minValue(0)->maxValue(240)->suffix('min')->default(0),
                TextInput::make('max_quantity')->label('Cantitate maximă')->numeric()->minValue(1)->maxValue(100)->default(1),
                Toggle::make('is_active')->label('Activ')->inline(false),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->reorderable('sort')
            ->columns([
                TextColumn::make('name')->label('Extra-opțiune')->formatStateUsing(fn ($state, $record) => $record->getTranslation('name', 'ro')),
                TextColumn::make('price_minor')->label('Preț')->formatStateUsing(fn ($state) => Money::format($state))->placeholder('De completat'),
                TextColumn::make('pricing_model')->label('Calcul')->formatStateUsing(fn ($state) => $state === 'per_person' ? 'Per participant' : 'Per rezervare'),
                ToggleColumn::make('is_active')->label('Activ'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageAddons::route('/')];
    }
}
