<?php

namespace App\Filament\Resources\GalleryItems;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Media\MediaResource;
use App\Filament\Support\Fields;
use App\Modules\Cms\Models\GalleryItem;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class GalleryItemResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = GalleryItem::class;

    protected static string $viewPermission = 'cms.manage';

    protected static string|\UnitEnum|null $navigationGroup = 'Conținut site';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $modelLabel = 'imagine în galerie';

    protected static ?string $pluralModelLabel = 'Galerie';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Fields::media('media_id', 'Imagine din bibliotecă')->required(),
            Grid::make(2)->schema([
                TextInput::make('album')->label('Album')->required()->default('general')->maxLength(40)->datalist(fn () => GalleryItem::query()->distinct()->pluck('album')->all()),
                Toggle::make('is_published')->label('Publicată')->inline(false),
            ]),
            Fields::localeTabs(fn (string $l) => [TextInput::make("caption.{$l}")->label('Legendă (opțional)')->maxLength(200)], 'gallery'),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->reorderable('sort')
            ->columns([
                ImageColumn::make('thumb')->label('')->state(fn (GalleryItem $record) => $record->media ? MediaResource::thumbUrl($record->media) : null)->imageHeight(56)->imageWidth(84)->extraImgAttributes(['style' => 'object-fit:cover;border-radius:.375rem', 'alt' => '']),
                TextColumn::make('media.static_key')->label('Fișier')->state(fn (GalleryItem $record) => $record->media?->static_key ?? $record->media?->original_name),
                TextColumn::make('album')->label('Album')->badge(),
                TextColumn::make('rights')->label('Publicabilă')->state(fn (GalleryItem $record) => $record->media?->isPublishable() ? 'Da' : 'Nu — vezi drepturile')->color(fn ($state) => $state === 'Da' ? 'success' : 'warning'),
                ToggleColumn::make('is_published')->label('Publicată'),
            ])
            ->filters([SelectFilter::make('album')->options(fn () => GalleryItem::query()->distinct()->pluck('album', 'album')->all())])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageGalleryItems::route('/')];
    }
}
