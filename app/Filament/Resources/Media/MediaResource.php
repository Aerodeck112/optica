<?php

namespace App\Filament\Resources\Media;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\Fields;
use App\Modules\Cms\Models\Media;
use App\Modules\Cms\Services\MediaProcessor;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class MediaResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Media::class;

    protected static string $viewPermission = 'cms.manage';

    protected static string|\UnitEnum|null $navigationGroup = 'Conținut site';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static ?string $modelLabel = 'fișier media';

    protected static ?string $pluralModelLabel = 'Bibliotecă media';

    protected static ?string $slug = 'media';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Fișier')->schema([
                FileUpload::make('upload')
                    ->label('Fișier')
                    ->disk('public')
                    ->directory(fn () => 'media/'.now()->format('Y/m'))
                    ->visibility('public')
                    ->acceptedFileTypes([...MediaProcessor::ALLOWED_IMAGE_MIMES, ...MediaProcessor::ALLOWED_VIDEO_MIMES])
                    ->maxSize(40 * 1024)
                    ->getUploadedFileNameForStorageUsing(fn ($file) => Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension() ?: $file->guessExtension()))
                    ->storeFileNamesIn('original_name')
                    ->required()
                    ->helperText('JPEG, PNG sau WebP (max. 40 MP) ori video MP4/WebM. Metadatele EXIF (inclusiv locația GPS) sunt eliminate automat; se generează variante optimizate.')
                    ->visibleOn('create'),
                Hidden::make('original_name'),
                ViewField::make('focal_picker')
                    ->label('Punct focal')
                    ->view('filament.media.focal-point')
                    ->dehydrated(false)
                    ->visible(fn (?Media $record) => $record?->isImage())
                    ->helperText('Click pe imagine pentru a alege zona care rămâne vizibilă la decupări (mobil, carduri).'),
                Grid::make(2)->schema([
                    TextInput::make('focal_x')->label('Focal X (0–1)')->numeric()->minValue(0)->maxValue(1)->step(0.01),
                    TextInput::make('focal_y')->label('Focal Y (0–1)')->numeric()->minValue(0)->maxValue(1)->step(0.01),
                ])->visible(fn (?Media $record) => $record?->isImage()),
            ]),
            Section::make('Text alternativ și descriere')->schema([
                Fields::localeTabs(fn (string $locale, bool $isDefault) => [
                    TextInput::make("alt.{$locale}")->label('Text alternativ (alt)')->maxLength(200)
                        ->required(fn (Get $get) => $isDefault && $get('kind') !== 'video')
                        ->helperText('Descrie ce se vede, pentru cititoare de ecran. Fără „imagine cu…”. Pentru imaginile pur decorative, scrie „-”.'),
                    TextInput::make("caption.{$locale}")->label('Legendă (opțional)')->maxLength(240),
                ], 'media'),
            ]),
            Section::make('Proveniență și drepturi')->schema([
                Grid::make(2)->schema([
                    Select::make('category')->label('Categorie')->options(Media::CATEGORIES)->required()->default('local_photo')
                        ->helperText('Fotografiile din alte locații sau imaginile din jocuri nu se prezintă drept fotografii din arena din Cluj.'),
                    Select::make('rights_status')->label('Drept de utilizare')->options(Media::RIGHTS)->required()->default('unknown')
                        ->helperText('„Necunoscut” = fișierul nu apare pe site.'),
                    TextInput::make('source')->label('Sursă (de unde provine)')->maxLength(255),
                    TextInput::make('credit')->label('Credit foto / autor')->maxLength(160),
                ]),
                Textarea::make('rights_notes')->label('Note drepturi (licență, acorduri, restricții)')->rows(2)->maxLength(2000),
                Grid::make(2)->schema([
                    Toggle::make('depicts_minors')->label('Apar minori în imagine')->live(),
                    Toggle::make('guardian_consent_confirmed')->label('Acordul părinților/tutorilor pentru publicare este confirmat')
                        ->visible(fn (Get $get) => (bool) $get('depicts_minors'))
                        ->helperText('Fără această confirmare, imaginea nu apare pe site.'),
                ]),
            ]),
            Section::make('Unde este folosit')->schema([
                Text::make(fn (?Media $record) => self::usageText($record)),
            ])->visibleOn('edit')->collapsible(),
            Hidden::make('kind'),
        ])->columns(1);
    }

    public static function usageText(?Media $record): string
    {
        if (! $record) {
            return '';
        }
        $usage = $record->usageSummary();
        if (! $usage) {
            return 'Nu este folosit în pagini, jocuri, pachete sau articole.';
        }

        return collect($usage)->map(fn ($u) => "{$u['type']} #{$u['id']} ({$u['field']})")->implode(' · ');
    }

    public static function thumbUrl(Media $media): ?string
    {
        $url = $media->isImage() ? $media->urlForWidth(480, ['webp', 'jpg']) : $media->poster?->urlForWidth(480, ['webp', 'jpg']);

        return $url && str_starts_with($url, '/') ? url($url) : $url;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->contentGrid(['md' => 3, 'xl' => 5])
            ->paginated([20, 40, 80])
            ->columns([
                Stack::make([
                    ImageColumn::make('thumb')->state(fn (Media $m) => self::thumbUrl($m))->imageHeight(140)->extraImgAttributes(['style' => 'width:100%;object-fit:cover;border-radius:.5rem', 'loading' => 'lazy', 'alt' => '']),
                    TextColumn::make('label')->state(fn (Media $m) => $m->static_key ?: $m->original_name ?: $m->filename)->weight('medium')->limit(40)->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($query) => $query->where('original_name', 'like', "%{$search}%")->orWhere('static_key', 'like', "%{$search}%")->orWhere('alt', 'like', "%{$search}%"))),
                    TextColumn::make('category')->formatStateUsing(fn ($state) => Media::CATEGORIES[$state] ?? $state)->size('xs')->color('gray'),
                    TextColumn::make('flags')->state(function (Media $m) {
                        $flags = [];
                        if ($m->rights_status === 'unknown') {
                            $flags[] = 'Drepturi necunoscute';
                        }
                        if ($m->depicts_minors && ! $m->guardian_consent_confirmed) {
                            $flags[] = 'Minori — acord neconfirmat';
                        }
                        if ($m->isImage() && ! $m->getTranslation('alt', 'ro', false)) {
                            $flags[] = 'Fără text alternativ';
                        }

                        return $flags ?: null;
                    })->badge()->color('warning'),
                ])->space(1),
            ])
            ->filters([
                SelectFilter::make('category')->label('Categorie')->options(Media::CATEGORIES),
                SelectFilter::make('rights_status')->label('Drepturi')->options(Media::RIGHTS),
                SelectFilter::make('kind')->label('Tip')->options(['image' => 'Imagine', 'video' => 'Video', 'document' => 'Document']),
                TernaryFilter::make('depicts_minors')->label('Cu minori'),
                TernaryFilter::make('unused')->label('Nefolosite')->queries(
                    true: fn (Builder $query) => $query->whereDoesntHave('usages'),
                    false: fn (Builder $query) => $query->whereHas('usages'),
                ),
            ])
            ->recordActions([
                EditAction::make()->label('Detalii'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMedia::route('/'),
            'create' => Pages\CreateMedia::route('/create'),
            'edit' => Pages\EditMedia::route('/{record}/edit'),
        ];
    }
}
