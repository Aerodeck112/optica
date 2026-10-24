<?php

namespace App\Filament\Resources\Games;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\Fields;
use App\Modules\Catalog\Models\Game;
use App\Modules\Catalog\Models\GameGenre;
use App\Modules\Cms\Models\Media;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class GameResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Game::class;

    protected static string $viewPermission = 'catalog.manage';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $modelLabel = 'joc VR';

    protected static ?string $pluralModelLabel = 'Jocuri VR';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Disponibilitate la Cluj')->schema([
                Select::make('cluj_status')->label('Stare')->options(Game::STATUSES)->required()
                    ->helperText('Pe site apar doar jocurile „Disponibil” și „În curând”. Jocurile neconfirmate rămân ascunse (implicit).'),
                Grid::make(3)->schema([
                    Toggle::make('is_featured')->label('Recomandat pe prima pagină'),
                    Toggle::make('use_in_ai')->label('Folosit de asistentul AI'),
                    TextInput::make('sort')->label('Ordine')->numeric()->default(0),
                ]),
            ]),
            Section::make('Date confirmate pentru arena din Cluj')
                ->description('Completează doar valori confirmate. Câmpurile goale nu se afișează pe site (nu se inventează valori).')
                ->schema([
                    Grid::make(4)->schema([
                        Select::make('genre_id')->label('Gen')->options(fn () => GameGenre::query()->orderBy('sort')->get()->mapWithKeys(fn ($g) => [$g->id => $g->getTranslation('name', 'ro')]))->placeholder('De completat'),
                        Select::make('audience')->label('Public')->options(Game::AUDIENCES)->required(),
                        TextInput::make('min_age')->label('Vârstă minimă')->numeric()->minValue(3)->maxValue(18)->placeholder('De completat')->suffix('ani'),
                        Select::make('intensity')->label('Intensitate')->options(Game::INTENSITY)->placeholder('De completat'),
                        TextInput::make('min_players')->label('Jucători min.')->numeric()->minValue(1)->maxValue(50)->placeholder('De completat'),
                        TextInput::make('max_players')->label('Jucători max.')->numeric()->minValue(1)->maxValue(50)->placeholder('De completat')->gte('min_players'),
                        TextInput::make('play_minutes')->label('Durata jocului')->numeric()->minValue(1)->maxValue(240)->suffix('min')->placeholder('De completat')
                            ->helperText('Doar timpul efectiv de joc. Durata rezervării (cu instructaj) se setează la pachet.'),
                    ]),
                ]),
            Section::make('Valori de referință (franciză) — nu se publică')
                ->schema([
                    Text::make(fn (?Game $record) => new HtmlString(self::referenceHtml($record))),
                ])->collapsible()->collapsed()->visibleOn('edit'),
            Fields::localeTabs(fn (string $locale, bool $isDefault) => [
                Grid::make(2)->schema([
                    TextInput::make("title.{$locale}")->label('Titlu')->required($isDefault)->maxLength(120),
                    TextInput::make("slug.{$locale}")->label('Adresă (slug)')->required($isDefault)->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->maxLength(80),
                ]),
                TextInput::make("tagline.{$locale}")->label('Descriere scurtă')->maxLength(160),
                MarkdownEditor::make("description.{$locale}")->label('Descriere')->toolbarButtons([['bold', 'italic', 'link'], ['bulletList', 'orderedList'], ['undo', 'redo']])->maxLength(6000),
                Textarea::make("rules.{$locale}")->label('Reguli / ce trebuie să știe jucătorii')->rows(3)->maxLength(2000),
                Section::make('SEO')->schema([
                    TextInput::make("seo_title.{$locale}")->label('Titlu SEO')->maxLength(70),
                    Textarea::make("seo_description.{$locale}")->label('Descriere SEO')->rows(2)->maxLength(170),
                ])->collapsible()->collapsed()->compact(),
            ], 'game'),
            Section::make('Imagini și video')->schema([
                Fields::media('cover_id', 'Imagine principală'),
                Select::make('gallery_ids')->label('Galerie')->multiple()->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Media::query()->where('kind', 'image')->where(fn ($q) => $q->where('static_key', 'like', "%{$search}%")->orWhere('original_name', 'like', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn ($m) => [$m->id => Fields::mediaLabel($m)])->all())
                    ->getOptionLabelsUsing(fn (array $values) => Media::query()->whereIn('id', $values)->get()->mapWithKeys(fn ($m) => [$m->id => Fields::mediaLabel($m)])->all()),
                Fields::media('trailer_id', 'Trailer video', 'video'),
                Text::make('Imaginile din jocuri sunt randări, nu fotografii din arenă.'),
            ])->columns(1),
            Section::make('Publicare')->schema([Fields::publishedLocales()]),
        ])->columns(1);
    }

    public static function referenceHtml(?Game $record): string
    {
        $data = (array) ($record?->reference_data ?? []);
        if (! $data) {
            return 'Nu există valori de referință.';
        }
        $html = '<p>'.e($data['note'] ?? '').'</p><table class="aw-table"><thead><tr><th>Sursă</th><th>Jucători</th><th>Vârstă</th><th>Durată</th></tr></thead><tbody>';
        foreach ((array) ($data['sources'] ?? []) as $src) {
            $html .= '<tr><td>'.e($src['source'] ?? '').'</td><td>'.e($src['players'] ?? '—').'</td><td>'.e($src['age'] ?? '—').'</td><td>'.e(isset($src['minutes']) ? $src['minutes'].' min' : '—').'</td></tr>';
        }

        return $html.'</tbody></table>';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                ImageColumn::make('cover_thumb')->label('')->state(fn (Game $g) => $g->cover ? url($g->cover->urlForWidth(480, ['webp', 'jpg'])) : null)->imageHeight(48)->imageWidth(64)->extraImgAttributes(['style' => 'object-fit:cover;border-radius:.375rem', 'alt' => '']),
                TextColumn::make('title')->label('Joc')->searchable(query: fn ($query, $search) => $query->where('title->ro', 'like', "%{$search}%"))->description(fn (Game $g) => $g->genre?->getTranslation('name', 'ro')),
                TextColumn::make('cluj_status')->label('La Cluj')->badge()->formatStateUsing(fn ($state) => Game::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'available' => 'success', 'coming_soon' => 'info', 'unconfirmed' => 'warning', default => 'gray'
                    }),
                TextColumn::make('players')->label('Jucători')->state(fn (Game $g) => $g->min_players && $g->max_players ? "{$g->min_players}–{$g->max_players}" : null)->placeholder('De completat')->color(fn ($state) => $state ? null : 'warning'),
                TextColumn::make('min_age')->label('Vârstă')->formatStateUsing(fn ($state) => $state ? "{$state}+" : null)->placeholder('De completat'),
                TextColumn::make('play_minutes')->label('Durată joc')->suffix(' min')->placeholder('De completat'),
                ToggleColumn::make('is_featured')->label('Recomandat'),
                TextColumn::make('published_locales')->label('Limbi')->badge(),
            ])
            ->filters([
                SelectFilter::make('cluj_status')->label('Stare la Cluj')->options(Game::STATUSES),
                SelectFilter::make('genre_id')->label('Gen')->relationship('genre', 'name->ro'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGames::route('/'),
            'create' => Pages\CreateGame::route('/create'),
            'edit' => Pages\EditGame::route('/{record}/edit'),
        ];
    }
}
