<?php

namespace App\Filament\Resources\PlayerWaivers;

use App\Modules\Arena\Models\GameSession;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\WaiverCode;
use App\Modules\Booking\Services\WaiverCodeService;
use App\Support\LocalTime;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * „Coduri pentru tabletă”: recepția dă fiecărui jucător un cod de 6 cifre; pe tabletă, codul deschide
 * declarația legată de grup. Același buton apare la check-in, în rezervare, în sesiunea de joc și în lista declarațiilor.
 */
class WaiverCodeActions
{
    public static function canIssue(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->can('bookings.checkin') || $user?->can('sessions.operate'));
    }

    /**
     * @param  Closure(): ?Booking  $booking
     * @param  (Closure(): ?GameSession)|null  $session
     */
    public static function make(Closure $booking, ?Closure $session = null, string $name = 'waiverCodes'): Action
    {
        $session ??= fn () => null;

        return Action::make($name)->label('Coduri pentru tabletă')->icon('heroicon-o-device-tablet')->color('gray')
            ->visible(fn () => self::canIssue())
            ->modalHeading('Coduri pentru semnarea pe tabletă')
            ->modalDescription('Fiecare jucător primește un cod de 6 cifre, îl tastează pe tabletă și semnează declarația. Un cod se folosește o singură dată.')
            ->modalContent(fn () => self::summary(app(WaiverCodeService::class)->active($booking(), $session())->load('card')))
            ->fillForm(fn () => ['mode' => 'cards', 'count' => ($b = $booking()) ? max(1, app(WaiverCodeService::class)->missing($b)) : 1])
            ->schema([
                Radio::make('mode')->label('Ce primesc jucătorii')->options([
                    'cards' => 'Carduri fizice (refolosibile, cu cod de bare)',
                    'slips' => 'Bilețele tipărite cu coduri noi',
                ])->default('cards')->required()
                    ->helperText(fn () => 'Carduri libere la recepție: '.app(WaiverCodeService::class)->freeCards()->count().'. Dacă nu ajung, restul primesc bilețele.'),
                TextInput::make('count')->label('Câte coduri noi')->numeric()->minValue(1)->maxValue(WaiverCodeService::MAX_PER_ISSUE)->required()
                    ->helperText('Câte unul pentru fiecare jucător care nu a semnat încă.'),
            ])
            ->modalSubmitActionLabel('Generează codurile')
            ->action(function (array $data) use ($booking, $session) {
                $codes = app(WaiverCodeService::class)->issue($booking(), (int) $data['count'], $session(), auth()->id(), ($data['mode'] ?? 'cards') === 'cards');
                self::announce($codes, $booking(), $session());
            });
    }

    /** @param  Collection<int, WaiverCode>  $codes */
    public static function announce(Collection $codes, ?Booking $booking, ?GameSession $session = null): void
    {
        Notification::make()->success()->persistent()
            ->title(self::title($codes))
            ->body(new HtmlString(self::list($codes).'<p style="margin-top:.5rem">Valabile până la '.e($codes->first() ? LocalTime::format($codes->first()->expires_at, 'H:i') : '').'.'
                .($codes->contains(fn (WaiverCode $c) => ! $c->waiver_card_id) ? ' <a href="'.e(self::printUrl($booking, $session)).'" target="_blank" rel="noopener" style="text-decoration:underline">Tipărește bilețelele</a>' : ' După semnare, jucătorii returnează cardurile la recepție.').'</p>'))
            ->send();
    }

    /** @param  Collection<int, WaiverCode>  $codes */
    private static function title(Collection $codes): string
    {
        $cards = $codes->filter(fn (WaiverCode $c) => $c->waiver_card_id)->map(fn (WaiverCode $c) => str_pad((string) $c->card?->number, 2, '0', STR_PAD_LEFT));
        $slips = $codes->count() - $cards->count();
        $parts = [];
        if ($cards->isNotEmpty()) {
            $parts[] = 'Dă '.($cards->count() === 1 ? 'cardul nr. ' : 'cardurile nr. ').$cards->implode(', ');
        }
        if ($slips > 0) {
            $parts[] = ($cards->isNotEmpty() ? 'plus ' : '').($slips === 1 ? 'un bilețel cu cod' : $slips.' bilețele cu coduri');
        }

        return implode(' ', $parts);
    }

    /** @param  Collection<int, WaiverCode>  $codes */
    private static function summary(Collection $codes): HtmlString
    {
        if ($codes->isEmpty()) {
            return new HtmlString('<p style="opacity:.75">Nu există coduri nefolosite pentru acest grup.</p>');
        }

        return new HtmlString('<p style="font-weight:600;margin-bottom:.5rem">Coduri încă nefolosite</p>'.self::list($codes));
    }

    /** @param  Collection<int, WaiverCode>  $codes */
    private static function list(Collection $codes): string
    {
        return '<ul style="display:grid;grid-template-columns:repeat(auto-fill,minmax(9.5rem,1fr));gap:.5rem;list-style:none;padding:0;margin:0">'
            .$codes->map(fn (WaiverCode $c) => '<li style="border:1px solid rgb(127 127 127 / .35);border-radius:.6rem;padding:.4rem .6rem">'
                .'<span style="font-size:.75rem;opacity:.7">Jucător '.$c->position.($c->waiver_card_id ? ' · '.e((string) $c->card?->label()) : ' · bilețel').'</span><br>'
                .'<strong style="font-size:1.5rem;letter-spacing:.08em;font-variant-numeric:tabular-nums">'.e($c->formatted()).'</strong></li>')->implode('')
            .'</ul>';
    }

    public static function printUrl(?Booking $booking, ?GameSession $session = null): string
    {
        return route('admin.waivers.codes', array_filter(['booking' => $booking?->id, 'session' => $booking ? null : $session?->id]));
    }
}
