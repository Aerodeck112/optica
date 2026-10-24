<?php

namespace App\Filament\Resources\Rewards;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\Fields;
use App\Modules\Catalog\Models\Package;
use App\Modules\Loyalty\Models\Reward;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class RewardResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Reward::class;

    protected static string $viewPermission = 'loyalty.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Fidelizare';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $modelLabel = 'recompensă';

    protected static ?string $pluralModelLabel = 'Recompense';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Fields::localeTabs(fn (string $l, bool $d) => [
                TextInput::make("name.{$l}")->label('Nume')->required($d)->maxLength(120),
                Textarea::make("description.{$l}")->label('Descriere')->rows(2)->maxLength(500),
            ], 'reward'),
            Grid::make(3)->schema([
                TextInput::make('points_cost')->label('Cost (puncte)')->integer()->minValue(1)->required(),
                Select::make('reward_type')->label('Recompensă')->options(['voucher_value' => 'Voucher valoric', 'voucher_package' => 'Voucher pentru un pachet'])->required()->live(),
                Fields::money('value_minor', 'Valoare voucher')->visible(fn (Get $get) => $get('reward_type') === 'voucher_value')->required(fn (Get $get) => $get('reward_type') === 'voucher_value'),
                Select::make('package_id')->label('Pachet')->options(fn () => Package::query()->get()->mapWithKeys(fn ($p) => [$p->id => $p->getTranslation('name', 'ro')]))
                    ->visible(fn (Get $get) => $get('reward_type') === 'voucher_package')->required(fn (Get $get) => $get('reward_type') === 'voucher_package'),
                TextInput::make('voucher_valid_days')->label('Valabilitate voucher (zile)')->integer()->minValue(1)->default(180)->required(),
                Toggle::make('is_active')->label('Activă')->inline(false),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Recompensă')->formatStateUsing(fn ($state, $record) => $record->getTranslation('name', 'ro')),
            TextColumn::make('points_cost')->label('Puncte'),
            ToggleColumn::make('is_active')->label('Activă'),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageRewards::route('/')];
    }
}
