<?php

namespace App\Filament\Resources\PlayerWaivers\Widgets;

use App\Filament\Resources\PlayerWaivers\WaiverCodeActions;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\WaiverCode;
use App\Modules\Booking\Services\WaiverCodeService;
use App\Support\LocalTime;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * „Azi lipsesc declarații”: rezervările de azi la care nu au semnat toți participanții (online sau pe tabletă).
 * Recepția dă un cod persoanei din fața ei (întâi un card liber, altfel un bilețel) și persoana semnează pe tabletă.
 */
class MissingWaivers extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Azi lipsesc declarații';

    public static function canView(): bool
    {
        return WaiverCodeActions::canIssue();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Booking::query()
                ->withCount('waivers')
                ->onLocalDate(LocalTime::now()->toDateString())
                ->whereIn('status', ['pending', 'confirmed', 'checked_in'])
                ->whereRaw('(select count(*) from player_waivers pw where pw.booking_id = bookings.id) < bookings.participants')
                ->orderBy('starts_at'))
            ->paginated(false)
            ->columns([
                TextColumn::make('starts_at')->label('Ora')->formatStateUsing(fn ($state) => LocalTime::format($state, 'H:i'))->weight('bold'),
                TextColumn::make('contact_name')->label('Rezervare')->description(fn (Booking $record) => $record->code),
                TextColumn::make('waivers_count')->label('Au semnat')->badge()->color('warning')
                    ->formatStateUsing(fn ($state, Booking $record) => $state.' din '.$record->participants),
                TextColumn::make('codes')->label('Coduri date, încă nefolosite')->html()
                    ->state(fn (Booking $record) => app(WaiverCodeService::class)->active($record)->load('card')
                        ->map(fn (WaiverCode $c) => $c->waiver_card_id ? e((string) $c->card?->label()) : '<span style="font-variant-numeric:tabular-nums;letter-spacing:.05em">'.e($c->formatted()).'</span>')
                        ->implode(' · ') ?: '—'),
            ])
            ->recordActions([
                Action::make('one')->label('Cod pentru o persoană')->icon('heroicon-o-device-tablet')->button()->size('sm')
                    ->action(fn (Booking $record) => $this->give($record, 1)),
                Action::make('all')->label('Coduri pentru toți')->icon('heroicon-o-user-group')->color('gray')->size('sm')
                    ->visible(fn (Booking $record) => app(WaiverCodeService::class)->missing($record) > 1)
                    ->action(fn (Booking $record) => $this->give($record, app(WaiverCodeService::class)->missing($record))),
            ])
            ->headerActions([
                Action::make('walkIn')->label('Cod pentru o persoană fără rezervare')->icon('heroicon-o-device-tablet')->color('gray')->size('sm')
                    ->action(fn () => $this->give(null, 1)),
            ])
            ->emptyStateHeading('Toți cei cu rezervare azi au semnat declarația')
            ->emptyStateDescription('Pentru cineva venit fără rezervare, folosiți „Cod pentru o persoană fără rezervare”.');
    }

    private function give(?Booking $booking, int $count): void
    {
        abort_unless(WaiverCodeActions::canIssue(), 403);
        if ($count < 1) {
            Notification::make()->info()->title('Toți participanții au deja declarație sau cod.')->send();

            return;
        }
        WaiverCodeActions::announce(app(WaiverCodeService::class)->issue($booking, $count, null, auth()->id(), useCards: true), $booking);
    }
}
