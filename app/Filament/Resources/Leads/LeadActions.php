<?php

namespace App\Filament\Resources\Leads;

use App\Filament\Resources\Bookings\BookingActions;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Support\Fields;
use App\Modules\Booking\Services\BookingException;
use App\Modules\Catalog\Models\Package;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadActivity;
use App\Modules\Crm\Services\LeadService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;

final class LeadActions
{
    public static function all(): array
    {
        return [self::moveStage(), self::logActivity(), self::offer(), self::convert(), self::lose()];
    }

    public static function moveStage(): Action
    {
        return Action::make('move_stage')->label('Schimbă etapa')->icon('heroicon-o-arrow-right-circle')
            ->visible(fn (Lead $record) => ! $record->isClosed())
            ->fillForm(fn (Lead $record) => ['stage' => $record->stage])
            ->schema([
                Select::make('stage')->label('Etapă')->options(array_diff_key(Lead::STAGES, ['lost' => 1]))->required(),
                Textarea::make('note')->label('Notă (opțional)')->maxLength(1000),
            ])
            ->action(function (Lead $record, array $data) {
                app(LeadService::class)->moveTo($record, $data['stage'], auth()->id(), $data['note'] ?? null);
                Notification::make()->success()->title('Etapa a fost actualizată.')->send();
            });
    }

    public static function logActivity(): Action
    {
        return Action::make('log')->label('Notează contact')->icon('heroicon-o-chat-bubble-left-ellipsis')->color('gray')
            ->schema([
                Select::make('type')->label('Tip')->options(['call' => 'Apel', 'email' => 'E-mail', 'meeting' => 'Întâlnire', 'note' => 'Notă'])->default('call')->required(),
                Textarea::make('body')->label('Detalii')->required()->maxLength(2000),
            ])
            ->action(function (Lead $record, array $data) {
                LeadActivity::query()->create(['lead_id' => $record->id, 'user_id' => auth()->id(), 'type' => $data['type'], 'body' => $data['body']]);
                if ($record->stage === 'new' && in_array($data['type'], ['call', 'email', 'meeting'], true)) {
                    app(LeadService::class)->moveTo($record, 'contacted', auth()->id());
                }
                Notification::make()->success()->title('Activitatea a fost notată.')->send();
            });
    }

    public static function offer(): Action
    {
        return Action::make('offer')->label('Înregistrează oferta')->icon('heroicon-o-document-currency-euro')->color('gray')
            ->visible(fn (Lead $record) => ! $record->isClosed())
            ->fillForm(fn (Lead $record) => ['offer_amount_minor' => $record->offer_amount_minor, 'package_id' => $record->package_id])
            ->schema([
                Select::make('package_id')->label('Pachet propus')->options(fn () => Package::query()->get()->mapWithKeys(fn ($p) => [$p->id => $p->getTranslation('name', 'ro')])),
                Fields::money('offer_amount_minor', 'Valoare ofertă (cu TVA)')->required(),
                Textarea::make('body')->label('Ce include oferta')->maxLength(2000),
            ])
            ->action(function (Lead $record, array $data) {
                $record->forceFill(['offer_amount_minor' => $data['offer_amount_minor'], 'package_id' => $data['package_id'] ?? $record->package_id])->save();
                LeadActivity::query()->create(['lead_id' => $record->id, 'user_id' => auth()->id(), 'type' => 'offer', 'body' => $data['body'] ?? null, 'data' => ['amount_minor' => $data['offer_amount_minor']]]);
                if (in_array($record->stage, ['new', 'contacted'], true)) {
                    app(LeadService::class)->moveTo($record, 'offer_sent', auth()->id());
                }
                Notification::make()->success()->title('Oferta a fost înregistrată.')->send();
            });
    }

    public static function convert(): Action
    {
        return Action::make('convert')->label('Creează rezervarea')->icon('heroicon-o-calendar-days')->color('success')
            ->visible(fn (Lead $record) => ! $record->booking_id && ! in_array($record->stage, ['lost', 'completed'], true) && auth()->user()?->can('bookings.manage'))
            ->fillForm(fn (Lead $record) => ['package_id' => $record->package_id, 'participants' => $record->participants ?: 2, 'date' => $record->preferred_date?->toDateString()])
            ->schema([
                Select::make('package_id')->label('Pachet')->required()->live()->options(fn () => Package::query()->get()->mapWithKeys(fn ($p) => [$p->id => $p->getTranslation('name', 'ro')])),
                TextInput::make('participants')->label('Participanți')->integer()->minValue(1)->required()->live(onBlur: true),
                DatePicker::make('date')->label('Data')->required()->live()->native(false)->displayFormat('d.m.Y'),
                Select::make('slot')->label('Ora')->native()->required()
                    ->options(fn (Get $get) => ($p = Package::query()->find($get('package_id'))) ? BookingActions::slotOptions(null, $get('date'), (int) $get('participants'), $p) : [])
                    ->helperText('Doar intervalele libere. Pachetul trebuie să aibă durată, capacitate și resurse configurate.'),
            ])
            ->action(function (Lead $record, array $data, $livewire) {
                [$start, $resource] = explode('|', $data['slot']) + [1 => null];
                try {
                    $booking = app(LeadService::class)->convertToBooking($record, Package::query()->findOrFail($data['package_id']), CarbonImmutable::parse($start), (int) $data['participants'], auth()->id(), $resource ? (int) $resource : null);
                    Notification::make()->success()->title("Rezervarea {$booking->code} a fost creată.")->send();
                    $livewire->redirect(BookingResource::getUrl('view', ['record' => $booking]));
                } catch (BookingException $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                }
            });
    }

    public static function lose(): Action
    {
        return Action::make('lose')->label('Marchează pierdută')->icon('heroicon-o-x-mark')->color('danger')
            ->visible(fn (Lead $record) => ! $record->isClosed())
            ->schema([Select::make('reason')->label('Motiv')->required()->options([
                'price' => 'Preț', 'availability' => 'Nu era disponibilitate', 'no_response' => 'Clientul nu a mai răspuns', 'competitor' => 'A ales altă variantă', 'cancelled_event' => 'Evenimentul s-a anulat', 'other' => 'Alt motiv',
            ])])
            ->action(function (Lead $record, array $data) {
                app(LeadService::class)->moveTo($record, 'lost', auth()->id(), null, $data['reason']);
                Notification::make()->success()->title('Cererea a fost marcată pierdută.')->send();
            });
    }
}
