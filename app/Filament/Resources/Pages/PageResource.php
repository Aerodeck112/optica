<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Cms\BlockForms;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\Fields;
use App\Modules\Cms\Models\Page;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PageResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Page::class;

    protected static string $viewPermission = 'cms.manage';

    protected static ?string $deletePermission = 'cms.publish';

    protected static string|\UnitEnum|null $navigationGroup = 'Conținut site';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $modelLabel = 'pagină';

    protected static ?string $pluralModelLabel = 'Pagini';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public const STATUSES = ['draft' => 'Ciornă (nepublicată)', 'scheduled' => 'Programată', 'published' => 'Publicată'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Fields::localeTabs(fn (string $locale, bool $isDefault) => [
                TextInput::make("title.{$locale}")->label('Titlu pagină')->required($isDefault)->maxLength(120),
                TextInput::make("slug.{$locale}")->label('Adresă (slug)')->prefix(url($locale === 'ro' ? '/' : "/{$locale}/"))
                    ->required(fn (?Page $record) => $isDefault && $record?->key !== 'home')
                    ->disabled(fn (?Page $record) => $record?->key === 'home')
                    ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->maxLength(80)
                    ->helperText('Litere mici fără diacritice, cifre și cratime. La schimbare se creează automat o redirecționare 301.'),
                Section::make('SEO')->schema([
                    TextInput::make("seo_title.{$locale}")->label('Titlu SEO')->maxLength(70)->helperText('Recomandat: 50–60 de caractere. Gol = titlul paginii.'),
                    Textarea::make("seo_description.{$locale}")->label('Descriere SEO')->rows(2)->maxLength(170)->helperText('Recomandat: 120–160 de caractere.'),
                ])->collapsible()->compact(),
                BlockForms::builder("blocks.{$locale}")->helperText($isDefault ? null : 'Lasă gol dacă pagina nu are încă traducere în această limbă.'),
            ], 'page'),
            Section::make('Publicare și setări')->schema([
                Fields::publishedLocales(),
                Fields::media('og_image_id', 'Imagine pentru distribuire (Open Graph)'),
                Toggle::make('noindex')->label('Exclude din motoarele de căutare (noindex)'),
                TextInput::make('key')->label('Cheie internă')->helperText('Folosită în linkuri de tip page:cheie.')->required()->regex('/^[a-z0-9_\-]+$/')->maxLength(40)
                    ->unique(ignoreRecord: true)->disabled(fn (?Page $record) => (bool) $record?->is_system),
            ])->columns(2)->collapsible()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('title')->label('Pagină')->searchable(query: fn ($query, string $search) => $query->where('title->ro', 'like', "%{$search}%"))
                    ->description(fn (Page $record) => '/'.ltrim($record->localizedSlug('ro'), '/')),
                TextColumn::make('status')->label('Stare')->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'published' => 'success', 'scheduled' => 'info', default => 'gray'
                    }),
                IconColumn::make('has_draft')->label('Ciornă nepublicată')->boolean()->trueIcon('heroicon-o-pencil-square')->falseIcon('heroicon-o-minus')->trueColor('warning'),
                TextColumn::make('published_locales')->label('Limbi')->badge(),
                TextColumn::make('scheduled_at')->label('Programată')->dateTime('d.m.Y H:i', config('aw.timezone'))->placeholder('—'),
                TextColumn::make('updated_at')->label('Modificată')->since(config('aw.timezone'))->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stare')->options(self::STATUSES),
            ])
            ->recordActions([
                Action::make('view_live')->label('Vezi pe site')->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Page $record) => $record->url('ro'), shouldOpenInNewTab: true)
                    ->visible(fn (Page $record) => $record->isLive()),
                EditAction::make()->label('Editează'),
            ]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\RevisionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPages::route('/'),
            'create' => Pages\CreatePage::route('/create'),
            'edit' => Pages\EditPage::route('/{record}/edit'),
        ];
    }
}
