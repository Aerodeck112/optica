<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingActions;
use App\Filament\Resources\Bookings\BookingResource;
use App\Modules\Booking\Models\Resource as ArenaResource;
use App\Modules\Booking\Services\BookingData;
use App\Modules\Booking\Services\BookingException;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Booking\Services\PricingService;
use App\Modules\Catalog\Models\Package;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Rezervare introdusă de recepție (telefon, la ghișeu). Folosește același motor ca site-ul:
 * ora se alege doar dintre intervalele libere, iar suprapunerile sunt blocate tranzacțional.
 */
class CreateBooking extends CreateRecord
{
    protected static string $resource = BookingResource::class;

    protected static ?string $title = 'Rezervare nouă';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ce și când')->schema([
                Grid::make(3)->schema([
                    Select::make('package_id')->label('Pachet')->required()->live()
                        ->options(fn () => Package::query()->where('is_active', true)->orderBy('sort')->get()->mapWithKeys(fn ($p) => [$p->id => $p->getTranslation('name', 'ro')])),
                    TextInput::make('participants')->label('Participanți')->numeric()->minValue(1)->maxValue(200)->required()->default(2)->live(onBlur: true),
                    DatePicker::make('date')->label('Data')->native(false)->displayFormat('d.m.Y')->default(LocalTime::now()->toDateString())->live(),
                ]),
                Radio::make('slot')->label('Ora (doar intervalele libere)')->inline()
                    ->options(fn (Get $get) => ($p = Package::query()->find($get('package_id'))) ? BookingActions::slotOptions(null, $get('date'), (int) $get('participants'), $p) : [])
                    ->visible(fn (Get $get) => ($p = Package::query()->find($get('package_id'))) && $p->hasScheduling())
                    ->required(fn (Get $get) => ($p = Package::query()->find($get('package_id'))) && $p->hasScheduling())
                    ->helperText('Nu apare nicio oră? Ziua este închisă sau complet ocupată — verifică în Calendar.'),
                Grid::make(2)->schema([
                    TimePicker::make('manual_time')->label('Ora dorită (opțional)')->seconds(false)->minutesStep(15),
                    Text::make('Pachetul nu are încă durata, capacitatea sau resursele configurate: se înregistrează o CERERE, fără a ocupa arena. O confirmi ulterior din fișa rezervării.'),
                ])->visible(fn (Get $get) => ($p = Package::query()->find($get('package_id'))) && ! $p->hasScheduling()),
                Text::make(fn (Get $get) => $this->quoteText($get))->visible(fn (Get $get) => (bool) $get('package_id')),
            ]),
            Section::make('Client')->schema([
                Grid::make(3)->schema([
                    TextInput::make('contact_name')->label('Nume și prenume')->required()->maxLength(120),
                    TextInput::make('contact_phone')->label('Telefon')->tel()->maxLength(30)->requiredWithout('contact_email'),
                    TextInput::make('contact_email')->label('E-mail')->email()->maxLength(190)->requiredWithout('contact_phone')
                        ->helperText('Necesar pentru confirmare și remindere pe e-mail.'),
                    TextInput::make('company_name')->label('Companie (opțional)')->maxLength(190),
                ]),
                Grid::make(3)->schema([
                    TextInput::make('age_under_12')->label('Copii sub 12 ani')->numeric()->minValue(0)->default(0),
                    TextInput::make('age_12_17')->label('12–17 ani')->numeric()->minValue(0)->default(0),
                    TextInput::make('age_adults')->label('Adulți')->numeric()->minValue(0)->default(0),
                ]),
                Text::make('Pentru minori se înregistrează doar numărul pe grupe de vârstă — datele personale aparțin adultului responsabil.'),
            ]),
            Section::make('Detalii')->schema([
                Grid::make(3)->schema([
                    Select::make('staff_status')->label('Stare inițială')->options(['confirmed' => 'Confirmată', 'pending' => 'În așteptare (de confirmat)'])->default('confirmed')->required(),
                    Select::make('payment_method')->label('Plată')->options(['at_location' => 'La locație', 'bank_transfer' => 'Transfer bancar', 'online' => 'Online (link de plată)', 'voucher' => 'Voucher'])->default('at_location'),
                    Select::make('resource_id')->label('Resursă')->placeholder('Automat')
                        ->options(fn (Get $get) => ArenaResource::query()->whereIn('id', (array) Package::query()->find($get('package_id'))?->resource_ids)->pluck('name', 'id')),
                ]),
                Textarea::make('customer_notes')->label('Mesajul / cerințele clientului')->rows(2)->maxLength(2000),
                Textarea::make('internal_notes')->label('Note interne')->rows(2)->maxLength(5000),
            ])->collapsible(),
        ])->columns(1);
    }

    private function quoteText(Get $get): string
    {
        $package = Package::query()->find($get('package_id'));
        if (! $package) {
            return '';
        }
        $start = $get('slot') ? CarbonImmutable::parse(explode('|', $get('slot'))[0]) : null;
        $quote = app(PricingService::class)->quote($package, $start, max(1, (int) $get('participants')), []);

        return $quote->totalMinor === null
            ? 'Preț: de stabilit (pachet fără preț configurat / ofertă).'
            : 'Total estimat: '.Money::format($quote->totalMinor).($quote->depositMinor ? ' · avans: '.Money::format($quote->depositMinor) : '').' (cu TVA).';
    }

    protected function handleRecordCreation(array $data): Model
    {
        $package = Package::query()->findOrFail($data['package_id']);
        $start = null;
        $resourceId = $data['resource_id'] ?? null;
        if (! empty($data['slot'])) {
            [$iso, $slotResource] = explode('|', $data['slot']) + [1 => null];
            $start = CarbonImmutable::parse($iso);
            $resourceId ??= $slotResource;
        } elseif (! empty($data['date']) && ! empty($data['manual_time'])) {
            $start = LocalTime::fromLocal(substr($data['date'], 0, 10), substr($data['manual_time'], 0, 5));
        }

        try {
            $result = app(BookingService::class)->create(new BookingData(
                package: $package,
                startsAt: $start,
                participants: (int) $data['participants'],
                contactName: $data['contact_name'],
                contactEmail: $data['contact_email'] ?? null,
                contactPhone: $data['contact_phone'] ?? null,
                source: 'reception',
                ageGroups: array_filter(['under_12' => (int) ($data['age_under_12'] ?? 0), '12_17' => (int) ($data['age_12_17'] ?? 0), 'adults' => (int) ($data['age_adults'] ?? 0)]),
                companyName: $data['company_name'] ?? null,
                customerNotes: $data['customer_notes'] ?? null,
                paymentMethod: $data['payment_method'] ?? null,
                locale: 'ro',
                createdBy: auth()->id(),
                forceRequest: ! $package->hasScheduling(),
                staffStatus: $data['staff_status'] ?? 'confirmed',
                resourceId: $resourceId ? (int) $resourceId : null,
                internalNotes: $data['internal_notes'] ?? null,
            ));
        } catch (BookingException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
            throw new Halt;
        }

        return $result['booking'];
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Rezervarea '.$this->getRecord()->code.' a fost înregistrată.';
    }

    protected function getRedirectUrl(): string
    {
        return BookingResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
