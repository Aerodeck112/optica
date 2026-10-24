<?php

namespace App\Filament\Resources\Posts;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\Fields;
use App\Modules\Cms\Models\Post;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PostResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Post::class;

    protected static string $viewPermission = 'cms.manage';

    protected static string|\UnitEnum|null $navigationGroup = 'Conținut site';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-newspaper';

    protected static ?string $modelLabel = 'articol';

    protected static ?string $pluralModelLabel = 'Noutăți';

    protected static ?int $navigationSort = 5;

    public const STATUSES = ['draft' => 'Ciornă', 'published' => 'Publicat (la data aleasă)'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Fields::localeTabs(fn (string $l, bool $d) => [
                Grid::make(2)->schema([
                    TextInput::make("title.{$l}")->label('Titlu')->required($d)->maxLength(160),
                    TextInput::make("slug.{$l}")->label('Adresă (slug)')->required($d)->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->maxLength(100),
                ]),
                Textarea::make("excerpt.{$l}")->label('Rezumat')->rows(2)->maxLength(300),
                MarkdownEditor::make("body.{$l}")->label('Conținut')->required($d)->toolbarButtons([['bold', 'italic', 'link'], ['heading', 'bulletList', 'orderedList', 'blockquote'], ['undo', 'redo']])->maxLength(30000),
                Section::make('SEO')->schema([
                    TextInput::make("seo_title.{$l}")->label('Titlu SEO')->maxLength(70),
                    Textarea::make("seo_description.{$l}")->label('Descriere SEO')->rows(2)->maxLength(170),
                ])->collapsed()->collapsible()->compact(),
            ], 'post'),
            Section::make('Publicare')->schema([
                Grid::make(3)->schema([
                    Select::make('status')->label('Stare')->options(self::STATUSES)->default('draft')->required()
                        ->disabled(fn () => ! auth()->user()?->can('cms.publish'))->dehydrated(),
                    DateTimePicker::make('published_at')->label('Data publicării')->seconds(false)->timezone(config('aw.timezone'))->default(now())
                        ->helperText('O dată viitoare = publicare programată.'),
                    Fields::media('cover_id', 'Imagine'),
                ]),
                Fields::publishedLocales(),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('published_at', 'desc')
            ->columns([
                TextColumn::make('title')->label('Titlu')->state(fn (Post $record) => $record->getTranslation('title', 'ro'))
                    ->searchable(query: fn ($query, string $search) => $query->where('title->ro', 'like', "%{$search}%")),
                TextColumn::make('status')->label('Stare')->badge()
                    ->state(fn (Post $record) => $record->status !== 'published' ? 'Ciornă' : ($record->published_at?->isFuture() ? 'Programat' : 'Publicat'))
                    ->color(fn ($state) => match ($state) {
                        'Publicat' => 'success', 'Programat' => 'info', default => 'gray'
                    }),
                TextColumn::make('published_at')->label('Data')->dateTime('d.m.Y H:i', config('aw.timezone'))->sortable(),
                TextColumn::make('published_locales')->label('Limbi')->badge(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosts::route('/'),
            'create' => Pages\CreatePost::route('/create'),
            'edit' => Pages\EditPost::route('/{record}/edit'),
        ];
    }
}
