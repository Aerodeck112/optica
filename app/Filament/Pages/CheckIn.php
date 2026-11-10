<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\GameSessions\GameSessionResource;
use App\Filament\Resources\PlayerWaivers\PlayerWaiverResource;
use App\Modules\Arena\Services\GameSessionService;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingException;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Booking\Services\CheckinCode;
use App\Support\LocalTime;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Check-in la recepție: scaner QR (tastatură sau camera tabletei) ori codul rezervării tastat.
 * Lista sosirilor de azi permite check-in cu o singură atingere.
 */
class CheckIn extends Page
{
    protected string $view = 'filament.pages.check-in';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static string|\UnitEnum|null $navigationGroup = 'Recepție';

    protected static ?string $navigationLabel = 'Check-in';

    protected static ?string $title = 'Check-in';

    protected static ?int $navigationSort = 0;

    public string $code = '';

    public ?int $foundId = null;

    public ?string $message = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('bookings.checkin');
    }

    public function lookup(): void
    {
        $this->foundId = null;
        $this->message = null;
        $resolved = CheckinCode::resolve($this->code);
        if (! $resolved) {
            $this->message = 'Cod invalid. Verifică din nou sau caută clientul în lista de mai jos.';

            return;
        }
        $booking = Booking::query()->where('code', $resolved)->first();
        if (! $booking) {
            $this->message = "Nu există rezervarea {$resolved}.";

            return;
        }
        $this->foundId = $booking->id;
        $this->code = '';
    }

    public function checkIn(int $id): void
    {
        $booking = Booking::query()->findOrFail($id);
        try {
            app(BookingService::class)->transition($booking, 'checked_in', 'user', auth()->id());
            Notification::make()->success()->title("Check-in efectuat: {$booking->contact_name} ({$booking->participants} pers.)")->send();
        } catch (BookingException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    /** Deschide (sau creează) sesiunea de joc a rezervării: acolo se asociază participanții și se completează nickname-urile. */
    public function openSession(int $id)
    {
        abort_unless(auth()->user()?->can('sessions.operate'), 403);
        $session = app(GameSessionService::class)->forBooking(Booking::query()->findOrFail($id), auth()->id());

        return redirect(GameSessionResource::getUrl('view', ['record' => $session]));
    }

    protected function getViewData(): array
    {
        $today = LocalTime::now()->toDateString();

        return [
            'found' => $this->foundId ? Booking::query()->with('package')->withCount('waivers')->find($this->foundId) : null,
            'arrivals' => Booking::query()->with('package')->withCount('waivers')->onLocalDate($today)->whereIn('status', ['pending', 'confirmed', 'checked_in'])->orderBy('starts_at')->get(),
            'bookingUrl' => fn (Booking $b) => BookingResource::getUrl('view', ['record' => $b]),
            // datele din declarații le vede doar cine are acces; restul echipei vede doar numărul
            'waiversUrl' => auth()->user()?->can('waivers.view') ? fn (Booking $b) => PlayerWaiverResource::getUrl('index', ['tableSearch' => $b->code]) : null,
            'canSessions' => (bool) auth()->user()?->can('sessions.operate'),
        ];
    }
}
