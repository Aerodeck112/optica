<?php

namespace App\Filament\Resources\Bookings;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Customers\CustomerResource;
use App\Modules\Booking\Models\Booking;
use App\Modules\Catalog\Models\Package;
use App\Support\LocalTime;
use App\Support\Money;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BookingResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Booking::class;

    protected static string $viewPermission = 'bookings.view';

    protected static ?string $managePermission = 'bookings.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Recepție';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $modelLabel = 'rezervare';

    protected static ?string $pluralModelLabel = 'Rezervări';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'contact_name', 'contact_email', 'contact_phone', 'company_name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Client' => $record->contact_name, 'Data' => LocalTime::format($record->starts_at), 'Stare' => $record->statusLabel()];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Booking::query()->where('status', 'request')->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Cereri în așteptarea unui răspuns';
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'confirmed' => 'info',
            'checked_in' => 'primary',
            'completed' => 'success',
            'pending', 'request' => 'warning',
            'cancelled', 'no_show', 'expired' => 'gray',
            default => 'gray',
        };
    }

    public static function paymentColor(string $status): string
    {
        return match ($status) {
            'paid' => 'success',
            'deposit_paid', 'partially_paid' => 'info',
            'refunded', 'partially_refunded' => 'gray',
            default => 'warning',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('starts_at')->label('Data și ora')->dateTime('D, d.m.Y H:i', config('aw.timezone'))->sortable()->placeholder('Fără dată (cerere)')
                    ->description(fn (Booking $record) => $record->package?->getTranslation('name', 'ro')),
                TextColumn::make('code')->label('Cod')->searchable()->copyable()->fontFamily('mono'),
                TextColumn::make('contact_name')->label('Client')->searchable(['contact_name', 'contact_email', 'contact_phone', 'company_name'])
                    ->description(fn (Booking $record) => $record->company_name ?: $record->contact_phone),
                TextColumn::make('participants')->label('Pers.')->alignCenter(),
                TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => Booking::STATUSES[$state] ?? $state)->color(fn ($state) => self::statusColor($state)),
                TextColumn::make('payment_status')->label('Plată')->badge()->formatStateUsing(fn ($state) => Booking::PAYMENT_STATUSES[$state] ?? $state)->color(fn ($state) => self::paymentColor($state)),
                TextColumn::make('total_minor')->label('Total')->formatStateUsing(fn ($state) => Money::format($state))->placeholder('De stabilit')->alignEnd(),
                TextColumn::make('source')->label('Sursă')->formatStateUsing(fn ($state) => Booking::SOURCES[$state] ?? $state)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Creată')->since()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stare')->options(Booking::STATUSES)->multiple(),
                SelectFilter::make('payment_status')->label('Plată')->options(Booking::PAYMENT_STATUSES)->multiple(),
                SelectFilter::make('package_id')->label('Pachet')->options(fn () => Package::query()->get()->mapWithKeys(fn ($p) => [$p->id => $p->getTranslation('name', 'ro')])),
                SelectFilter::make('source')->label('Sursă')->options(Booking::SOURCES),
                Filter::make('date')->label('Perioadă')
                    ->schema([
                        DatePicker::make('from')->label('De la')->native(false)->displayFormat('d.m.Y'),
                        DatePicker::make('until')->label('Până la')->native(false)->displayFormat('d.m.Y'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (! empty($data['from'])) {
                            $query->where('starts_at', '>=', LocalTime::fromLocal(substr($data['from'], 0, 10), '00:00'));
                        }
                        if (! empty($data['until'])) {
                            $query->where('starts_at', '<', LocalTime::fromLocal(substr($data['until'], 0, 10), '00:00')?->addDay());
                        }
                    }),
            ])
            ->recordActions([
                ViewAction::make()->label('Deschide'),
                ActionGroup::make(BookingActions::all()),
            ])
            ->recordUrl(fn (Booking $record) => static::getUrl('view', ['record' => $record]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Section::make('Rezervare')->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('code')->label('Cod')->copyable()->fontFamily('mono')->size('lg')->weight('bold'),
                        TextEntry::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => Booking::STATUSES[$state] ?? $state)->color(fn ($state) => self::statusColor($state)),
                        TextEntry::make('starts_at')->label('Început')->state(fn (Booking $record) => $record->localStart())->placeholder('Fără dată — cerere'),
                        TextEntry::make('ends_at')->label('Sfârșit')->dateTime('H:i', config('aw.timezone'))->placeholder('—'),
                        TextEntry::make('package.name')->label('Pachet')->state(fn (Booking $record) => $record->package?->getTranslation('name', 'ro')),
                        TextEntry::make('game.title')->label('Joc ales')->state(fn (Booking $record) => $record->game?->getTranslation('title', 'ro'))->placeholder('Nespecificat'),
                        TextEntry::make('participants')->label('Participanți'),
                        TextEntry::make('age_groups')->label('Grupe de vârstă')->state(fn (Booking $record) => self::ageGroups($record))->placeholder('—'),
                        TextEntry::make('resources')->label('Resursă')->state(fn (Booking $record) => $record->occupancies->where('is_active', true)->map(fn ($o) => $o->resource?->name)->filter()->implode(', '))->placeholder('Neocupată (cerere)'),
                        TextEntry::make('source')->label('Sursă')->formatStateUsing(fn ($state) => Booking::SOURCES[$state] ?? $state),
                    ]),
                    TextEntry::make('customer_notes')->label('Mesajul clientului')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('internal_notes')->label('Note interne')->placeholder('—')->columnSpanFull(),
                ])->columnSpan(['lg' => 2]),
                Section::make('Client')->schema([
                    TextEntry::make('contact_name')->label('Nume'),
                    TextEntry::make('contact_phone')->label('Telefon')->url(fn ($state) => $state ? 'tel:'.$state : null)->placeholder('—'),
                    TextEntry::make('contact_email')->label('E-mail')->url(fn ($state) => $state ? 'mailto:'.$state : null)->placeholder('—'),
                    TextEntry::make('company_name')->label('Companie')->placeholder('—'),
                    TextEntry::make('customer.id')->label('Fișă client')->state(fn (Booking $record) => $record->customer ? 'Deschide fișa' : null)
                        ->url(fn (Booking $record) => $record->customer && class_exists(CustomerResource::class) ? CustomerResource::getUrl('view', ['record' => $record->customer]) : null)->placeholder('—'),
                ])->columnSpan(1),
            ]),
            Section::make('Preț și plată')->schema([
                RepeatableEntry::make('items')->hiddenLabel()->schema([
                    TextEntry::make('description')->label('Element'),
                    TextEntry::make('quantity')->label('Cant.')->formatStateUsing(fn ($state) => rtrim(rtrim(number_format((float) $state, 3, ',', '.'), '0'), ',')),
                    TextEntry::make('unit_price_minor')->label('Preț unitar')->formatStateUsing(fn ($state) => Money::format($state)),
                    TextEntry::make('total_minor')->label('Total')->formatStateUsing(fn ($state) => Money::format($state)),
                ])->columns(4)->placeholder('Prețul nu este stabilit încă (cerere / ofertă).'),
                Grid::make(5)->schema([
                    TextEntry::make('total_minor')->label('Total (cu TVA)')->formatStateUsing(fn ($state) => Money::format($state))->placeholder('De stabilit'),
                    TextEntry::make('deposit_minor')->label('Avans cerut')->formatStateUsing(fn ($state) => Money::format($state)),
                    TextEntry::make('paid_minor')->label('Încasat')->formatStateUsing(fn ($state) => Money::format($state)),
                    TextEntry::make('balance')->label('Rest de plată')->state(fn (Booking $record) => Money::format($record->balanceMinor())),
                    TextEntry::make('payment_status')->label('Stare plată')->badge()->formatStateUsing(fn ($state) => Booking::PAYMENT_STATUSES[$state] ?? $state)->color(fn ($state) => self::paymentColor($state)),
                ]),
            ])->collapsible(),
            Section::make('Istoric')->schema([
                RepeatableEntry::make('events')->hiddenLabel()->schema([
                    TextEntry::make('created_at')->label('Când')->dateTime('d.m.Y H:i', config('aw.timezone')),
                    TextEntry::make('type')->label('Eveniment')->formatStateUsing(fn ($state, $record) => self::eventLabel($state, $record)),
                    TextEntry::make('actor_type')->label('De către')->formatStateUsing(fn ($state) => ['user' => 'Personal', 'customer' => 'Client', 'system' => 'Sistem', 'ai' => 'Asistent AI'][$state] ?? $state),
                ])->columns(3),
            ])->collapsible(),
        ])->columns(1);
    }

    public static function ageGroups(Booking $record): ?string
    {
        $labels = ['under_12' => 'sub 12 ani', '12_17' => '12–17 ani', 'adults' => 'adulți'];
        $parts = [];
        foreach ((array) $record->age_groups as $key => $count) {
            if ((int) $count > 0) {
                $parts[] = $count.' '.($labels[$key] ?? $key);
            }
        }

        return $parts ? implode(', ', $parts) : null;
    }

    public static function eventLabel(?string $type, $event): string
    {
        $labels = ['created' => 'Creată', 'status_changed' => 'Stare schimbată', 'rescheduled' => 'Reprogramată', 'details_updated' => 'Detalii modificate', 'payment' => 'Plată', 'cancelled' => 'Anulată'];
        $label = $labels[$type] ?? $type;
        if ($event?->to_status) {
            $label .= ': '.(Booking::STATUSES[$event->from_status] ?? '—').' → '.(Booking::STATUSES[$event->to_status] ?? $event->to_status);
        }

        return $label;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookings::route('/'),
            'create' => Pages\CreateBooking::route('/create'),
            'view' => Pages\ViewBooking::route('/{record}'),
        ];
    }
}
