<?php

namespace App\Filament\Resources\Redirects;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Cms\Models\Redirect;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class RedirectResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Redirect::class;

    protected static string $viewPermission = 'cms.publish';

    protected static string|\UnitEnum|null $navigationGroup = 'Conținut site';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-right';

    protected static ?string $modelLabel = 'redirecționare';

    protected static ?string $pluralModelLabel = 'Redirecționări';

    protected static ?int $navigationSort = 9;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('from_path')->label('De la (cale)')->required()->regex('/^\/[^\s<>"\']*$/')->unique(ignoreRecord: true)->placeholder('/pagina-veche')->maxLength(255),
                TextInput::make('to_url')->label('Către')->required()->regex('/^(\/[^\s<>"\']*|https:\/\/[^\s<>"\']+)$/')->placeholder('/pagina-noua sau https://…')->maxLength(500),
                Select::make('status_code')->label('Tip')->options([301 => '301 — permanentă', 302 => '302 — temporară'])->default(301)->required(),
                Toggle::make('is_active')->label('Activă')->default(true)->inline(false),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('from_path')->label('De la')->searchable()->fontFamily('mono'),
                TextColumn::make('to_url')->label('Către')->fontFamily('mono'),
                TextColumn::make('status_code')->label('Cod'),
                TextColumn::make('hits')->label('Accesări')->sortable(),
                ToggleColumn::make('is_active')->label('Activă'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageRedirects::route('/')];
    }
}
