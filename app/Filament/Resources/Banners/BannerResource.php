<?php

namespace App\Filament\Resources\Banners;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\Fields;
use App\Modules\Cms\Models\Banner;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class BannerResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Banner::class;

    protected static string $viewPermission = 'cms.manage';

    protected static ?string $managePermission = 'cms.publish';

    protected static string|\UnitEnum|null $navigationGroup = 'Conținut site';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $modelLabel = 'banner';

    protected static ?string $pluralModelLabel = 'Bannere și anunțuri';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nume intern')->required()->maxLength(120),
            Fields::localeTabs(fn (string $l, bool $d) => [
                TextInput::make("text.{$l}")->label('Text')->required($d)->maxLength(200),
                TextInput::make("link_label.{$l}")->label('Text link')->maxLength(40),
            ], 'banner'),
            Grid::make(3)->schema([
                Fields::link('link_url'),
                Select::make('tone')->label('Ton')->options(['info' => 'Informare', 'promo' => 'Promoție', 'warning' => 'Atenționare'])->default('info')->required(),
                Select::make('placement')->label('Poziție')->options(['top' => 'Sus, pe toate paginile'])->default('top')->required(),
                DateTimePicker::make('starts_at')->label('Afișat de la')->seconds(false)->timezone(config('aw.timezone')),
                DateTimePicker::make('ends_at')->label('Până la')->seconds(false)->timezone(config('aw.timezone'))->after('starts_at'),
                Toggle::make('is_dismissible')->label('Poate fi închis de vizitator')->inline(false)->default(true),
                Toggle::make('is_active')->label('Activ')->inline(false),
            ]),
            Fields::publishedLocales(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->reorderable('sort')
            ->columns([
                TextColumn::make('name')->label('Banner')->description(fn (Banner $record) => $record->getTranslation('text', 'ro')),
                TextColumn::make('starts_at')->label('De la')->dateTime('d.m.Y H:i', config('aw.timezone'))->placeholder('—'),
                TextColumn::make('ends_at')->label('Până la')->dateTime('d.m.Y H:i', config('aw.timezone'))->placeholder('—'),
                ToggleColumn::make('is_active')->label('Activ'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageBanners::route('/')];
    }
}
