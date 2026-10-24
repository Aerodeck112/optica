<?php

namespace App\Filament\Resources\Menus;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\Fields;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Services\LinkResolver;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MenuResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Menu::class;

    protected static string $viewPermission = 'cms.publish';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Conținut site';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bars-3';

    protected static ?string $modelLabel = 'meniu';

    protected static ?string $pluralModelLabel = 'Meniuri';

    protected static ?int $navigationSort = 8;

    public static function canCreate(): bool
    {
        return false; // locațiile meniurilor sunt fixe în șabloanele site-ului
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nume')->required()->maxLength(80),
            Repeater::make('items')->relationship('items', fn ($query) => $query->orderBy('sort'))->label('Elemente')
                ->orderColumn('sort')
                ->schema([
                    Fields::localeTabs(fn (string $l, bool $d) => [TextInput::make("label.{$l}")->label('Text')->required($d)->maxLength(40)], 'menu-item'),
                    Grid::make(4)->schema([
                        Select::make('link_type')->label('Tip link')->options(['page' => 'Pagină', 'route' => 'Secțiune', 'url' => 'Adresă'])->required()->live()->default('page'),
                        Select::make('page_id')->label('Pagină')->options(fn () => Page::query()->get()->mapWithKeys(fn ($p) => [$p->id => $p->getTranslation('title', 'ro')]))->visible(fn (Get $get) => $get('link_type') === 'page')->required(fn (Get $get) => $get('link_type') === 'page'),
                        Select::make('route')->label('Secțiune')->options(LinkResolver::ROUTES)->visible(fn (Get $get) => $get('link_type') === 'route')->required(fn (Get $get) => $get('link_type') === 'route'),
                        Fields::link('url', 'Adresă')->visible(fn (Get $get) => $get('link_type') === 'url')->required(fn (Get $get) => $get('link_type') === 'url'),
                        Select::make('style')->label('Stil')->options(['link' => 'Link', 'button' => 'Buton evidențiat'])->default('link'),
                        Toggle::make('is_visible')->label('Vizibil')->default(true)->inline(false),
                        Toggle::make('open_new_tab')->label('Tab nou')->inline(false),
                    ]),
                ])
                ->itemLabel(fn (array $state) => $state['label']['ro'] ?? null)
                ->collapsible()->collapsed()->addActionLabel('Adaugă element'),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Meniu'),
            TextColumn::make('location')->label('Locație'),
            TextColumn::make('items_count')->counts('items')->label('Elemente'),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMenus::route('/'),
            'edit' => Pages\EditMenu::route('/{record}/edit'),
        ];
    }
}
