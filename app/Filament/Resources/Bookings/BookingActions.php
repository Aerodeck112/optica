<?php

namespace App\Filament\Resources\Bookings;

use App\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\Resource as ArenaResource;
use App\Modules\Booking\Services\AvailabilityService;
use App\Modules\Booking\Services\BookingException;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Catalog\Models\Package;
use App\Modules\Notifications\Services\Notifier;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Acțiunile asupra unei rezervări. Toate trec prin BookingService (aceleași reguli ca site-ul și asistentul AI):
 * verificare de disponibilitate cu blocare, istoric, notificări și remindere recalculate.
 */
final class BookingActions
{
    public static function all(): array
    {
        return [self::confirm(), self::checkIn(), self::complete(), self::noShow(), self::reschedule(), self::cancel(), self::editDetails(), self::resend()];
    }

    private static function can(string $permission): bool
    {
        return (bool) auth()->user()?->can($permission);
    }

    private static function run(Closure $callback, string $success): void
    {
        try {
            $callback();
            Notification::make()->success()->title($success)->send();
        } catch (BookingException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
        }
    }

    public static function confirm(): Action
    {
        return Action::make('confirm')
            ->label(fn (Booking $record) => $record->status === 'request' ? 'Confirmă și ocupă intervalul' : 'Confirmă')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Booking $record) => self::can('bookings.manage') && $record->canTransitionTo('confirmed'))
            ->schema(fn (Booking $record) => [
                Select::make('resource_id')->label('Resursă')->placeholder('Automat (prima liberă)')
                    ->options(ArenaResource::query()->whereIn('id', (array) $record->package?->resource_ids)->pluck('name', 'id'))
                    ->visible($record->status === 'request'),
                Toggle::make('notify')->label('Trimite confirmarea clientului')->default(true),
            ])
            ->modalDescription(fn (Booking $record) => $record->status === 'request'
                ? 'Cererea devine rezervare confirmată. Disponibilitatea se verifică acum; dacă intervalul s-a ocupat, confirmarea este refuzată.'
                : 'Rezervarea devine confirmată.')
            ->action(fn (Booking $record, array $data) => self::run(
                fn () => app(BookingService::class)->confirm($record, auth()->id(), (bool) ($data['notify'] ?? true), isset($data['resource_id']) ? (int) $data['resource_id'] : null),
                'Rezervarea a fost confirmată.'
            ));
    }

    public static function checkIn(): Action
    {
        return Action::make('check_in')->label('Check-in')->icon('heroicon-o-qr-code')->color('info')
            ->visible(fn (Booking $record) => self::can('bookings.checkin') && $record->canTransitionTo('checked_in') && $record->starts_at?->lessThanOrEqualTo(now()->addHours(2)))
            ->requiresConfirmation()
            ->modalDescription(fn (Booking $record) => "Grupul {$record->contact_name} ({$record->participants} participanți) a ajuns.")
            ->action(fn (Booking $record) => self::run(fn () => app(BookingService::class)->transition($record, 'checked_in', 'user', auth()->id()), 'Check-in efectuat.'));
    }

    public static function complete(): Action
    {
        return Action::make('complete')->label('Finalizează')->icon('heroicon-o-flag')->color('success')
            ->visible(fn (Booking $record) => self::can('bookings.checkin') && $record->canTransitionTo('completed') && ($record->status === 'checked_in' || $record->starts_at?->isPast()))
            ->requiresConfirmation()
            ->action(fn (Booking $record) => self::run(fn () => app(BookingService::class)->transition($record, 'completed', 'user', auth()->id()), 'Rezervarea a fost finalizată.'));
    }

    public static function noShow(): Action
    {
        return Action::make('no_show')->label('Neprezentare')->icon('heroicon-o-user-minus')->color('gray')
            ->visible(fn (Booking $record) => self::can('bookings.manage') && $record->canTransitionTo('no_show') && $record->starts_at?->isPast())
            ->requiresConfirmation()
            ->modalDescription('Intervalul rămas se eliberează. Clientul nu este notificat automat.')
            ->action(fn (Booking $record) => self::run(fn () => app(BookingService::class)->transition($record, 'no_show', 'user', auth()->id()), 'Marcată ca neprezentare.'));
    }

    public static function reschedule(): Action
    {
        return Action::make('reschedule')->label('Reprogramează')->icon('heroicon-o-calendar-days')->color('warning')
            ->visible(fn (Booking $record) => self::can('bookings.manage') && in_array($record->status, ['pending', 'confirmed'], true) && $record->starts_at !== null)
            ->fillForm(fn (Booking $record) => ['date' => LocalTime::localDate($record->starts_at), 'participants' => $record->participants, 'notify' => true])
            ->schema([
                DatePicker::make('date')->label('Data nouă')->required()->live()->native(false)->displayFormat('d.m.Y'),
                Select::make('slot')->label('Ora nouă')->required()->native()
                    ->options(fn (Get $get, Booking $record) => self::slotOptions($record, $get('date'), (int) $record->participants))
                    ->helperText('Sunt afișate doar orele libere (rezervarea curentă nu blochează propriul interval).'),
                Toggle::make('notify')->label('Anunță clientul pe e-mail')->default(true),
            ])
            ->action(function (Booking $record, array $data) {
                [$start, $resourceId] = explode('|', $data['slot']) + [1 => null];
                self::run(fn () => app(BookingService::class)->reschedule($record, CarbonImmutable::parse($start), 'user', auth()->id(), (bool) $data['notify'], $resourceId ? (int) $resourceId : null), 'Rezervarea a fost reprogramată. Reminderele au fost recalculate.');
            });
    }

    public static function slotOptions(?Booking $record, ?string $date, int $participants, ?Package $package = null): array
    {
        $package ??= $record?->package;
        if (! $date || ! $package) {
            return [];
        }
        $result = app(AvailabilityService::class)->slotsFor($package, substr($date, 0, 10), max(1, $participants), 0, $record?->id, staff: true);
        $options = [];
        foreach ($result['slots'] as $slot) {
            if ($slot['available']) {
                $options[$slot['starts_at'].'|'.$slot['resource_id']] = $slot['local_time'];
            }
        }

        return $options;
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')->label('Anulează')->icon('heroicon-o-x-circle')->color('danger')
            ->visible(fn (Booking $record) => self::can('bookings.cancel') && $record->canTransitionTo('cancelled'))
            ->schema([
                Textarea::make('reason')->label('Motiv (intern)')->required()->maxLength(500),
                Toggle::make('notify')->label('Anunță clientul pe e-mail')->default(true),
            ])
            ->modalDescription('Intervalul se eliberează, iar reminderele programate se anulează. Rambursările (dacă e cazul) se fac din modulul Plăți.')
            ->action(fn (Booking $record, array $data) => self::run(fn () => app(BookingService::class)->cancel($record, $data['reason'], 'user', auth()->id(), (bool) $data['notify']), 'Rezervarea a fost anulată.'));
    }

    public static function editDetails(): Action
    {
        return Action::make('edit_details')->label('Editează detalii')->icon('heroicon-o-pencil-square')->color('gray')
            ->visible(fn () => self::can('bookings.manage'))
            ->fillForm(fn (Booking $record) => $record->only(['contact_name', 'contact_email', 'contact_phone', 'company_name', 'customer_notes', 'internal_notes', 'assigned_to']))
            ->schema([
                TextInput::make('contact_name')->label('Nume contact')->required()->maxLength(120),
                TextInput::make('contact_email')->label('E-mail')->email()->maxLength(190),
                TextInput::make('contact_phone')->label('Telefon')->tel()->maxLength(30),
                TextInput::make('company_name')->label('Companie')->maxLength(190),
                Textarea::make('customer_notes')->label('Mesajul clientului')->rows(2)->maxLength(2000),
                Textarea::make('internal_notes')->label('Note interne (nu le vede clientul)')->rows(3)->maxLength(5000),
                Select::make('assigned_to')->label('Responsabil')->options(fn () => User::query()->where('is_active', true)->pluck('name', 'id')),
            ])
            ->action(function (Booking $record, array $data) {
                $before = $record->only(array_keys($data));
                $record->forceFill($data)->save();
                app(BookingService::class)->recordEvent($record, 'details_updated', null, null, 'user', auth()->id(), ['fields' => array_keys(array_diff_assoc(array_map('strval', $data), array_map('strval', $before)))]);
                Notification::make()->success()->title('Detaliile au fost actualizate.')->send();
            });
    }

    public static function resend(): Action
    {
        return Action::make('resend')->label('Retrimite confirmarea')->icon('heroicon-o-envelope')->color('gray')
            ->visible(fn (Booking $record) => self::can('bookings.manage') && $record->customer && $record->contact_email && in_array($record->status, ['pending', 'confirmed', 'request'], true))
            ->requiresConfirmation()
            ->action(function (Booking $record) {
                $template = match ($record->status) {
                    'request' => 'booking.request_received', 'confirmed' => 'booking.confirmed', default => 'booking.pending'
                };
                $log = app(Notifier::class)->sendToCustomer($record->customer, $template, 'email', ['booking' => $record, 'manage_url' => $record->manageUrl()], 'service', "booking:{$record->id}:resend:".now()->format('YmdHi'), $record);
                $log
                    ? Notification::make()->success()->title('Mesajul a fost pus în coada de trimitere.')->send()
                    : Notification::make()->warning()->title('Mesajul nu a fost trimis (adresă suprimată sau deja trimis în acest minut).')->send();
            });
    }
}
