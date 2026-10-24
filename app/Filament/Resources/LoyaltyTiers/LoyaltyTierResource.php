<?php

namespace App\Filament\Resources\LoyaltyTiers;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\Fields;
use App\Modules\Loyalty\Models\LoyaltyTier;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LoyaltyTierResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = LoyaltyTier::class;

    protected static string $viewPermission = 'loyalty.manage';

    protected static string|\UnitEnum|null $navigationGroup = 'Fidelizare';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $modelLabel = 'nivel';

    protected static ?string $pluralModelLabel = 'Niveluri de fidelitate';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Fields::localeTabs(fn (string $l, bool $d) => [TextInput::make("name.{$l}")->label('Nume nivel')->required($d)->maxLength(60)], 'tier'),
            Grid::make(2)->schema([
                TextInput::make('min_lifetime_points')->label('Puncte acumulate necesare')->integer()->minValue(0)->required(),
                TextInput::make('earn_multiplier')->label('Multiplicator puncte')->numeric()->minValue(1)->maxValue(5)->step(0.05)->default(1)->required(),
            ]),
            TagsInput::make('benefits')->label('Beneficii (afișate clientului)'),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('min_lifetime_points')->columns([
            TextColumn::make('name')->label('Nivel')->formatStateUsing(fn ($state, $record) => $record->getTranslation('name', 'ro')),
            TextColumn::make('min_lifetime_points')->label('De la (puncte)'),
            TextColumn::make('earn_multiplier')->label('Multiplicator')->suffix('×'),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageLoyaltyTiers::route('/')];
    }
}
