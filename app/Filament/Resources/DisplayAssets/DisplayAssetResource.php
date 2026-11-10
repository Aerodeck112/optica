<?php

namespace App\Filament\Resources\DisplayAssets;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Media\MediaResource;
use App\Filament\Support\Fields;
use App\Modules\Cms\Models\Media;
use App\Modules\Cms\Services\MediaProcessor;
use App\Modules\Display\Models\DisplayAsset;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Biblioteca promoțională a ecranelor: imagini și videoclipuri din biblioteca media (sau încărcate aici, prin aceeași
 * procesare: fără metadate EXIF, cu variante optimizate). Imaginile nu se deformează: „contain” pune materialul întreg
 * pe un fundal estompat din aceeași imagine; materialele verticale se afișează automat așa.
 */
class DisplayAssetResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = DisplayAsset::class;

    protected static string $viewPermission = 'display.manage';

    protected static string|\UnitEnum|null $navigationGroup = 'Ecrane afișare';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static ?string $modelLabel = 'material promoțional';

    protected static ?string $pluralModelLabel = 'Biblioteca promoțională';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Fișier')->schema([
                Radio::make('source')->label('Sursa')->options(['library' => 'Din biblioteca media', 'upload' => 'Încarc un fișier nou'])->default('library')->live()->inline()
                    ->dehydrated(false)->visibleOn('create'),
                Select::make('kind')->label('Tip')->options(DisplayAsset::KINDS)->required()->live()->default('image'),
                Select::make('media_id')->label('Fișier din biblioteca media')->searchable()
                    ->options(fn (Get $get) => Media::query()->where('kind', $get('kind') ?: 'image')->latest('id')->limit(60)->get()->mapWithKeys(fn (Media $m) => [$m->id => Fields::mediaLabel($m)]))
                    ->getSearchResultsUsing(fn (string $search, Get $get) => Media::query()->where('kind', $get('kind') ?: 'image')
                        ->where(fn ($q) => $q->where('original_name', 'like', "%{$search}%")->orWhere('static_key', 'like', "%{$search}%"))
                        ->limit(30)->get()->mapWithKeys(fn (Media $m) => [$m->id => Fields::mediaLabel($m)])->all())
                    ->getOptionLabelUsing(fn ($value) => ($m = Media::query()->find($value)) ? Fields::mediaLabel($m) : null)
                    ->visible(fn (Get $get, string $operation) => $operation === 'edit' || $get('source') !== 'upload')
                    ->required(fn (Get $get, string $operation) => $operation === 'edit' || $get('source') !== 'upload')
                    ->helperText('Se acceptă doar JPEG, PNG, WebP, AVIF, MP4 și WebM, cu drepturi de utilizare cunoscute.'),
                FileUpload::make('upload')->label('Fișier nou')->disk('public')->directory(fn () => 'media/'.now()->format('Y/m'))->visibility('public')
                    ->acceptedFileTypes(fn (Get $get) => $get('kind') === 'video' ? DisplayAsset::VIDEO_MIMES : DisplayAsset::IMAGE_MIMES)
                    ->maxSize(40 * 1024)
                    ->getUploadedFileNameForStorageUsing(fn ($file) => Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension() ?: $file->guessExtension()))
                    ->storeFileNamesIn('original_name')
                    ->visible(fn (Get $get, string $operation) => $operation === 'create' && $get('source') === 'upload')
                    ->required(fn (Get $get, string $operation) => $operation === 'create' && $get('source') === 'upload')
                    ->helperText('Recomandat: 1920 × 1080 (orizontal). Materialele verticale se afișează întregi, pe fundal estompat. Video MP4 (H.264), fără sunet sau cu sunet opțional.'),
                Hidden::make('original_name'),
                Select::make('rights_status')->label('Drepturi de utilizare (fișier nou)')->options(array_diff_key(Media::RIGHTS, ['unknown' => 1]))->default('owned')
                    ->visible(fn (Get $get, string $operation) => $operation === 'create' && $get('source') === 'upload')
                    ->required(fn (Get $get, string $operation) => $operation === 'create' && $get('source') === 'upload'),
            ]),
            Section::make('Afișare')->schema([
                Grid::make(3)->schema([
                    TextInput::make('title')->label('Denumire (internă)')->required()->maxLength(120),
                    Select::make('fit')->label('Încadrare')->options(DisplayAsset::FITS)->default('cover')->required(),
                    Select::make('screens')->label('Ecrane')->options(DisplayAsset::SCREENS)->default('all')->required(),
                    TextInput::make('caption')->label('Text pe ecran (opțional)')->maxLength(160)->columnSpan(2),
                    TextInput::make('weight')->label('Prioritate / pondere (1–10)')->numeric()->minValue(1)->maxValue(10)->default(5)->required(),
                    TextInput::make('duration_seconds')->label(fn (Get $get) => $get('kind') === 'video' ? 'Durata maximă (s)' : 'Durată (s)')->numeric()->minValue(3)->maxValue(600)->default(8)->required()
                        ->helperText('Pentru video: se oprește la final sau la această limită, care e mai devreme.'),
                    DateTimePicker::make('starts_at')->label('Afișat de la')->seconds(false)->timezone(config('aw.timezone')),
                    DateTimePicker::make('ends_at')->label('Până la')->seconds(false)->timezone(config('aw.timezone'))->after('starts_at'),
                    Toggle::make('is_active')->label('Activ')->default(true)->inline(false),
                ]),
                Text::make('Materialele în afara perioadei sau inactive nu apar pe ecrane. Un fișier defect se sare automat.'),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->modifyQueryUsing(fn (Builder $query) => $query->with('media.poster'))
            ->columns([
                ImageColumn::make('thumb')->label('')->state(fn (DisplayAsset $a) => $a->media ? ($a->kind === 'video' ? ($a->media->poster ? MediaResource::thumbUrl($a->media->poster) : null) : MediaResource::thumbUrl($a->media)) : null)
                    ->imageHeight(54),
                TextColumn::make('title')->label('Material')->weight('bold')->searchable()->description(fn (DisplayAsset $a) => $a->caption),
                TextColumn::make('kind')->label('Tip')->badge()->formatStateUsing(fn ($state) => DisplayAsset::KINDS[$state] ?? $state),
                TextColumn::make('media.duration_seconds')->label('Durată video')->suffix(' s')->placeholder('—')->toggleable(),
                TextColumn::make('fit')->label('Încadrare')->formatStateUsing(fn ($state) => $state === 'cover' ? 'Umple' : 'Întreg'),
                TextColumn::make('weight')->label('Pondere'),
                TextColumn::make('screens')->label('Ecrane')->formatStateUsing(fn ($state) => DisplayAsset::SCREENS[$state] ?? $state),
                TextColumn::make('ends_at')->label('Până la')->dateTime('d.m.Y H:i', config('aw.timezone'))->placeholder('Fără termen'),
                ToggleColumn::make('is_active')->label('Activ'),
            ])
            ->filters([SelectFilter::make('kind')->label('Tip')->options(DisplayAsset::KINDS)])
            ->recordActions([EditAction::make()]);
    }

    /** Verifică formatul fișierului ales și potrivirea cu tipul materialului. */
    public static function assertMedia(Media $media, string $kind): ?string
    {
        $allowed = $kind === 'video' ? DisplayAsset::VIDEO_MIMES : DisplayAsset::IMAGE_MIMES;
        if ($media->kind !== $kind || ! in_array($media->mime, $allowed, true)) {
            return 'Format nepotrivit: alege '.($kind === 'video' ? 'un video MP4 sau WebM' : 'o imagine JPEG, PNG, WebP sau AVIF').'.';
        }
        if ($media->rights_status === 'unknown') {
            return 'Fișierul are drepturi de utilizare necunoscute și nu se poate afișa. Completează drepturile în biblioteca media.';
        }

        return null;
    }

    public static function storeUpload(array $data): Media
    {
        $path = is_array($data['upload']) ? reset($data['upload']) : $data['upload'];
        $original = is_array($data['original_name'] ?? null) ? (string) reset($data['original_name']) : (string) ($data['original_name'] ?? basename($path));

        return app(MediaProcessor::class)->createFromStoredFile($path, $original, [
            'category' => 'brand_art',
            'rights_status' => $data['rights_status'] ?? 'owned',
            'source' => 'Ecrane afișare',
        ], auth()->id());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDisplayAssets::route('/'),
            'create' => Pages\CreateDisplayAsset::route('/create'),
            'edit' => Pages\EditDisplayAsset::route('/{record}/edit'),
        ];
    }
}
