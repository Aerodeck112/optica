<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Crm\Models\Customer;
use App\Modules\Loyalty\Services\LoyaltyService;
use App\Support\ContactNormalizer;
use App\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CustomerResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Customer::class;

    protected static string $viewPermission = 'customers.view';

    protected static ?string $managePermission = 'customers.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'CRM';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $modelLabel = 'client';

    protected static ?string $pluralModelLabel = 'Clienți';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'email';

    public static function getGloballySearchableAttributes(): array
    {
        return ['first_name', 'last_name', 'email', 'phone', 'company_name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->fullName();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('merged_into_id');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Date de contact')->schema([
                Grid::make(3)->schema([
                    Select::make('type')->label('Tip')->options(['person' => 'Persoană fizică', 'company' => 'Companie'])->required()->live(),
                    TextInput::make('first_name')->label('Prenume')->maxLength(80),
                    TextInput::make('last_name')->label('Nume')->maxLength(80),
                    TextInput::make('email')->label('E-mail')->email()->maxLength(190)
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->whereNull('merged_into_id')),
                    TextInput::make('phone')->label('Telefon')->tel()->maxLength(30),
                    Select::make('locale')->label('Limba preferată')->options(['ro' => 'Română', 'en' => 'English', 'hu' => 'Magyar'])->default('ro'),
                ]),
            ]),
            Section::make('Date de facturare')->schema([
                Grid::make(3)->schema([
                    TextInput::make('company_name')->label('Denumire companie')->maxLength(190)->required(fn (Get $get) => $get('type') === 'company'),
                    TextInput::make('company_tax_id')->label('CUI')->maxLength(20),
                    TextInput::make('company_reg_no')->label('Nr. Reg. Com.')->maxLength(30),
                    TextInput::make('billing_address')->label('Adresă')->maxLength(255)->columnSpan(2),
                    TextInput::make('billing_city')->label('Localitate')->maxLength(80),
                    TextInput::make('billing_county')->label('Județ')->maxLength(80),
                ]),
            ])->collapsible(),
            Section::make('Segmentare')->schema([
                TagsInput::make('tags')->label('Etichete')->suggestions(['corporate', 'aniversare', 'școală', 'vip', 'grup mare']),
                TagsInput::make('interests')->label('Interese (jocuri, tipuri de evenimente)'),
            ])->collapsible(),
        ])->columns(1);
    }

    /** Normalizarea contactelor înainte de salvare păstrează deduplicarea corectă. */
    public static function normalize(array $data): array
    {
        if (array_key_exists('email', $data)) {
            $data['email_normalized'] = ContactNormalizer::email($data['email']);
        }
        if (array_key_exists('phone', $data)) {
            $data['phone_normalized'] = ContactNormalizer::phone($data['phone']);
        }

        return $data;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Section::make('Client')->schema([
                    TextEntry::make('name')->label('Nume')->state(fn (Customer $record) => $record->fullName())->weight('bold'),
                    TextEntry::make('email')->label('E-mail')->placeholder('—')->copyable(),
                    TextEntry::make('phone')->label('Telefon')->placeholder('—')->url(fn ($state) => $state ? 'tel:'.$state : null),
                    TextEntry::make('company_name')->label('Companie')->placeholder('—'),
                    TextEntry::make('tags')->label('Etichete')->badge()->placeholder('—'),
                    TextEntry::make('anonymized_at')->label('Anonimizat')->dateTime('d.m.Y', config('aw.timezone'))->visible(fn (Customer $record) => $record->isAnonymized())->color('danger'),
                ])->columnSpan(1),
                Section::make('Activitate')->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('bookings_count')->label('Rezervări'),
                        TextEntry::make('visits_count')->label('Vizite'),
                        TextEntry::make('paid_total_minor')->label('Total încasat')->formatStateUsing(fn ($state) => Money::format((int) $state)),
                        TextEntry::make('last_visit_at')->label('Ultima vizită')->date('d.m.Y')->placeholder('—'),
                        TextEntry::make('loyalty')->label('Puncte fidelitate')->state(fn (Customer $record) => app(LoyaltyService::class)->balance($record)),
                        TextEntry::make('tier.name')->label('Nivel')->state(fn (Customer $record) => $record->tier?->getTranslation('name', 'ro'))->placeholder('—'),
                        TextEntry::make('consent_email')->label('Marketing e-mail')->state(fn (Customer $record) => $record->hasMarketingConsent('email') ? 'Da' : 'Nu')->badge()->color(fn ($state) => $state === 'Da' ? 'success' : 'gray'),
                        TextEntry::make('consent_sms')->label('Marketing SMS')->state(fn (Customer $record) => $record->hasMarketingConsent('sms') ? 'Da' : 'Nu')->badge()->color(fn ($state) => $state === 'Da' ? 'success' : 'gray'),
                        TextEntry::make('source')->label('Sursă')->placeholder('—'),
                    ]),
                ])->columnSpan(['lg' => 2]),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_booking_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Client')->state(fn (Customer $record) => $record->fullName())
                    ->searchable(['first_name', 'last_name', 'company_name', 'email', 'phone'])
                    ->description(fn (Customer $record) => $record->type === 'company' ? 'Companie' : null),
                TextColumn::make('email')->label('E-mail')->placeholder('—')->toggleable(),
                TextColumn::make('phone')->label('Telefon')->placeholder('—'),
                TextColumn::make('bookings_count')->label('Rezervări')->sortable()->alignCenter(),
                TextColumn::make('visits_count')->label('Vizite')->sortable()->alignCenter(),
                TextColumn::make('paid_total_minor')->label('Încasat')->formatStateUsing(fn ($state) => Money::format((int) $state))->sortable()->alignEnd(),
                TextColumn::make('last_booking_at')->label('Ultima rezervare')->date('d.m.Y')->sortable()->placeholder('—'),
                IconColumn::make('marketing')->label('Marketing')->state(fn (Customer $record) => $record->consents->where('status', 'granted')->isNotEmpty())->boolean(),
                TextColumn::make('tags')->label('Etichete')->badge()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with('consents'))
            ->filters([
                SelectFilter::make('type')->label('Tip')->options(['person' => 'Persoane', 'company' => 'Companii']),
                TernaryFilter::make('marketing')->label('Consimțământ marketing')->queries(
                    true: fn (Builder $query) => $query->whereHas('consents', fn ($q) => $q->where('status', 'granted')),
                    false: fn (Builder $query) => $query->whereDoesntHave('consents', fn ($q) => $q->where('status', 'granted')),
                ),
                TernaryFilter::make('anonymized')->label('Anonimizați')->default(false)->queries(
                    true: fn (Builder $query) => $query->whereNotNull('anonymized_at'),
                    false: fn (Builder $query) => $query->whereNull('anonymized_at'),
                ),
            ])
            ->recordActions([ViewAction::make()->label('Fișă')]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\BookingsRelationManager::class,
            RelationManagers\NotesRelationManager::class,
            RelationManagers\ConsentEventsRelationManager::class,
            RelationManagers\LoyaltyRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'view' => Pages\ViewCustomer::route('/{record}'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
