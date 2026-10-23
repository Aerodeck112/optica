<?php

namespace App\Filament\Resources\Packages;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\Fields;
use App\Modules\Booking\Models\Resource as ArenaResource;
use App\Modules\Catalog\Models\Package;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PackageResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Package::class;

    protected static string $viewPermission = 'catalog.manage';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $modelLabel = 'pachet';

    protected static ?string $pluralModelLabel = 'Pachete și tarife';

    protected static ?int $navigationSort = 2;

    public const PRICING = [
        'per_person' => 'Preț per participant',
        'flat' => 'Preț fix per rezervare',
        'flat_plus_extra' => 'Preț fix + participant suplimentar',
        'quote' => 'La cerere (ofertă personalizată)',
    ];

    public const DEPOSITS = ['none' => 'Fără avans', 'fixed' => 'Sumă fixă', 'percent' => 'Procent din total'];

    public const VAT_GROUPS = ['standard' => 'Cota standard', 'reduced' => 'Cota redusă', 'exempt' => 'Scutit'];

    public static function form(Schema $schema): Schema
    {
        $canPrice = fn () => auth()->user()?->can('prices.manage');

        return $schema->components([
            Section::make('Tip și mod de rezervare')->schema([
                Grid::make(3)->schema([
                    Select::make('type')->label('Tip pachet')->options(Package::TYPES)->required(),
                    Select::make('booking_mode')->label('Mod rezervare')->options(['instant' => 'Rezervare online instant', 'request' => 'Cerere de disponibilitate / ofertă'])->required()->live()
                        ->helperText('„Instant” funcționează doar dacă sunt completate prețul, durata, capacitatea și resursele.'),
                    Select::make('session_mode')->label('Tip sesiune')->options(['private' => 'Privată (arena doar pentru grup)', 'shared' => 'Comună (grupuri diferite în aceeași sesiune)'])->required(),
                ]),
                Text::make(fn (Get $get, ?Package $record) => self::readiness($get))->visible(fn (Get $get) => $get('booking_mode') === 'instant'),
                Grid::make(3)->schema([
                    Toggle::make('is_active')->label('Activ (vizibil și rezervabil)'),
                    Toggle::make('is_featured')->label('Evidențiat'),
                    Toggle::make('use_in_ai')->label('Folosit de asistentul AI'),
                ]),
            ]),
            Section::make('Durată și capacitate')->description('Durata rezervării include instructajul și echiparea; durata jocului este timpul efectiv în VR.')->schema([
                Grid::make(4)->schema([
                    TextInput::make('slot_minutes')->label('Durata rezervării')->numeric()->minValue(5)->maxValue(600)->suffix('min')->placeholder('De completat'),
                    TextInput::make('play_minutes')->label('Durata jocului')->numeric()->minValue(1)->maxValue(600)->suffix('min')->placeholder('De completat')->lte('slot_minutes'),
                    TextInput::make('buffer_before_minutes')->label('Pauză înainte')->numeric()->minValue(0)->maxValue(120)->suffix('min')->default(0),
                    TextInput::make('buffer_after_minutes')->label('Pauză după (curățare)')->numeric()->minValue(0)->maxValue(120)->suffix('min')->default(0),
                    TextInput::make('min_participants')->label('Participanți min.')->numeric()->minValue(1)->maxValue(200)->placeholder('De completat'),
                    TextInput::make('max_participants')->label('Participanți max.')->numeric()->minValue(1)->maxValue(200)->placeholder('De completat')->gte('min_participants'),
                    TextInput::make('min_age')->label('Vârstă minimă')->numeric()->minValue(3)->maxValue(18)->suffix('ani')->placeholder('De completat'),
                    TextInput::make('min_lead_minutes')->label('Rezervare cu minim')->numeric()->minValue(0)->maxValue(60 * 24 * 30)->suffix('min înainte')->placeholder('Setare generală'),
                ]),
                CheckboxList::make('resource_ids')->label('Resurse folosite (arene / zone)')->options(fn () => ArenaResource::query()->where('is_active', true)->orderBy('sort')->pluck('name', 'id'))->columns(3)
                    ->helperText('Fără resurse, pachetul nu poate calcula disponibilitatea și funcționează doar prin cerere.'),
                Toggle::make('requires_adult_contact')->label('Rezervarea se face de un adult responsabil (pentru grupuri cu minori)')->default(true),
            ]),
            Section::make('Preț')->description('Prețurile includ TVA. Un preț gol înseamnă „neconfigurat” — pachetul afișează „Cere ofertă”, nu 0 lei.')->schema([
                Grid::make(3)->schema([
                    Select::make('pricing_model')->label('Model de preț')->options(self::PRICING)->required()->live(),
                    Fields::money('price_minor', 'Preț de bază')->placeholder('De completat')->hidden(fn (Get $get) => $get('pricing_model') === 'quote'),
                    TextInput::make('included_participants')->label('Participanți incluși')->numeric()->minValue(1)->visible(fn (Get $get) => $get('pricing_model') === 'flat_plus_extra'),
                    Fields::money('extra_person_price_minor', 'Preț participant suplimentar')->visible(fn (Get $get) => $get('pricing_model') === 'flat_plus_extra'),
                    Select::make('vat_group')->label('TVA')->options(self::VAT_GROUPS)->required()->helperText('Cota exactă se aplică după data rezervării (vezi Cote TVA).'),
                ]),
                Grid::make(3)->schema([
                    Select::make('deposit_type')->label('Avans')->options(self::DEPOSITS)->required()->live(),
                    TextInput::make('deposit_value')->label('Valoare avans')->suffix(fn (Get $get) => $get('deposit_type') === 'percent' ? '%' : 'lei')
                        ->visible(fn (Get $get) => in_array($get('deposit_type'), ['fixed', 'percent'], true))->required(fn (Get $get) => in_array($get('deposit_type'), ['fixed', 'percent'], true))
                        ->rule('regex:/^\d{1,7}([.,]\d{1,2})?$/')
                        ->formatStateUsing(fn ($state) => $state === null ? null : Money::toDecimalString((int) $state))
                        ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : Money::parse($state)),
                ]),
            ])->disabled(fn () => ! $canPrice()),
            Section::make('Prețuri speciale (zile, ore, perioade)')->schema([
                Repeater::make('priceRules')->relationship()->label('')
                    ->schema([
                        Grid::make(4)->schema([
                            TextInput::make('label')->label('Denumire internă')->required()->maxLength(120),
                            Fields::money('price_minor', 'Preț de bază'),
                            Fields::money('extra_person_price_minor', 'Participant suplimentar'),
                            TextInput::make('priority')->label('Prioritate')->numeric()->default(100)->helperText('Număr mic = se aplică primul.'),
                        ]),
                        CheckboxList::make('weekdays')->label('Zile')->options([1 => 'Lu', 2 => 'Ma', 3 => 'Mi', 4 => 'Jo', 5 => 'Vi', 6 => 'Sâ', 7 => 'Du'])->columns(7),
                        Grid::make(4)->schema([
                            TimePicker::make('time_from')->label('De la ora')->seconds(false),
                            TimePicker::make('time_until')->label('Până la ora')->seconds(false),
                            DatePicker::make('valid_from')->label('Valabil de la'),
                            DatePicker::make('valid_until')->label('Valabil până la')->afterOrEqual('valid_from'),
                        ]),
                        Toggle::make('is_active')->label('Activ')->default(true),
                    ])
                    ->itemLabel(fn (array $state) => $state['label'] ?? null)
                    ->collapsible()->collapsed()->defaultItems(0)->addActionLabel('Adaugă preț special'),
            ])->collapsible()->disabled(fn () => ! $canPrice()),
            Fields::localeTabs(fn (string $locale, bool $isDefault) => [
                Grid::make(2)->schema([
                    TextInput::make("name.{$locale}")->label('Nume pachet')->required($isDefault)->maxLength(120),
                    TextInput::make("slug.{$locale}")->label('Adresă (slug)')->required($isDefault)->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->maxLength(80),
                ]),
                Textarea::make("summary.{$locale}")->label('Rezumat')->rows(2)->maxLength(300),
                MarkdownEditor::make("description.{$locale}")->label('Descriere')->toolbarButtons([['bold', 'italic', 'link'], ['bulletList'], ['undo', 'redo']])->maxLength(5000),
                Textarea::make("included.{$locale}")->label('Ce include (câte un element pe rând)')->rows(4)->maxLength(2000),
                Textarea::make("conditions.{$locale}")->label('Condiții')->rows(3)->maxLength(3000),
            ], 'package'),
            Section::make('Jocuri, extra-opțiuni și imagine')->schema([
                Select::make('games')->label('Jocuri incluse')->relationship('games', 'id')->multiple()->preload()
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->getTranslation('title', 'ro')),
                Select::make('addons')->label('Extra-opțiuni disponibile')->relationship('addons', 'id')->multiple()->preload()
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->getTranslation('name', 'ro')),
                Fields::media('cover_id', 'Imagine'),
            ]),
            Section::make('Publicare')->schema([Fields::publishedLocales()]),
        ])->columns(1);
    }

    public static function readiness(Get $get): string
    {
        $missing = [];
        if (! $get('slot_minutes')) {
            $missing[] = 'durata rezervării';
        }
        if (! $get('max_participants')) {
            $missing[] = 'capacitatea maximă';
        }
        if (empty($get('resource_ids'))) {
            $missing[] = 'resursele';
        }
        if ($get('pricing_model') === 'quote' || $get('price_minor') === null || $get('price_minor') === '') {
            $missing[] = 'prețul';
        }

        return $missing
            ? '⚠ Rezervarea instant nu este încă posibilă — lipsesc: '.implode(', ', $missing).'. Până atunci, clienții trimit o cerere de disponibilitate.'
            : '✓ Pachetul poate fi rezervat online instant.';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('name')->label('Pachet')->searchable(query: fn ($query, $search) => $query->where('name->ro', 'like', "%{$search}%"))->description(fn (Package $p) => Package::TYPES[$p->type] ?? $p->type),
                TextColumn::make('booking_mode')->label('Rezervare')->badge()
                    ->state(fn (Package $p) => $p->supportsInstantBooking() ? 'Instant' : 'Cerere')
                    ->color(fn ($state) => $state === 'Instant' ? 'success' : 'warning'),
                TextColumn::make('price_minor')->label('Preț')->formatStateUsing(fn ($state, Package $p) => Money::format($state).($p->pricing_model === 'per_person' ? ' / pers.' : ''))->placeholder('De completat'),
                TextColumn::make('slot_minutes')->label('Durată rezervare')->suffix(' min')->placeholder('De completat'),
                TextColumn::make('capacity')->label('Participanți')->state(fn (Package $p) => $p->max_participants ? ($p->min_participants ?: 1).'–'.$p->max_participants : null)->placeholder('De completat'),
                ToggleColumn::make('is_active')->label('Activ'),
            ])
            ->filters([SelectFilter::make('type')->label('Tip')->options(Package::TYPES)])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPackages::route('/'),
            'create' => Pages\CreatePackage::route('/create'),
            'edit' => Pages\EditPackage::route('/{record}/edit'),
        ];
    }
}
