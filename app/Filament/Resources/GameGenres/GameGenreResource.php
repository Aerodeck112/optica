<?php

namespace App\Filament\Resources\GameGenres;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\Fields;
use App\Modules\Catalog\Models\GameGenre;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GameGenreResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = GameGenre::class;

    protected static string $viewPermission = 'catalog.manage';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?string $modelLabel = 'gen';

    protected static ?string $pluralModelLabel = 'Genuri de jocuri';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Fields::localeTabs(fn (string $l, bool $d) => [TextInput::make("name.{$l}")->label('Nume')->required($d)->maxLength(60)], 'genre'),
            TextInput::make('slug')->label('Cheie URL (filtru)')->required()->regex('/^[a-z0-9\-]+$/')->unique(ignoreRecord: true)->maxLength(60),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->reorderable('sort')
            ->columns([
                TextColumn::make('name')->label('Gen')->formatStateUsing(fn ($state, $record) => $record->getTranslation('name', 'ro')),
                TextColumn::make('slug')->label('Cheie'),
                TextColumn::make('games_count')->counts('games')->label('Jocuri'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()->disabled(fn ($record) => $record->games()->exists())->tooltip('Genurile folosite de jocuri nu pot fi șterse.')]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageGameGenres::route('/')];
    }
}
