<?php

namespace App\Filament\Resources\PlayerWaivers\Pages;

use App\Filament\Resources\PlayerWaivers\PlayerWaiverResource;
use App\Filament\Resources\PlayerWaivers\WaiverCodeActions;
use App\Filament\Resources\PlayerWaivers\Widgets\MissingWaivers;
use App\Modules\Audit\AuditLogger;
use App\Modules\Booking\Models\WaiverCard;
use App\Modules\Booking\Services\WaiverCodeService;
use App\Support\LocalTime;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListPlayerWaivers extends ListRecords
{
    protected static string $resource = PlayerWaiverResource::class;

    protected function getHeaderWidgets(): array
    {
        return [MissingWaivers::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('qr')->label('Cod QR pentru recepție')->icon('heroicon-o-qr-code')->color('gray')
                ->url(route('admin.waivers.qr'))->openUrlInNewTab(),
            WaiverCodeActions::make(fn () => null)->label('Coduri fără rezervare')
                ->modalDescription('Pentru jucătorii veniți fără rezervare. Fiecare primește un cod de 6 cifre și semnează pe tabletă; declarația nu se leagă de nicio rezervare.'),
            Action::make('cards')->label('Carduri fizice')->icon('heroicon-o-credit-card')->color('gray')
                ->visible(fn () => WaiverCodeActions::canIssue())
                ->modalHeading('Cardurile pentru tabletă')
                ->modalContent(fn () => new HtmlString(self::cardsTable()))
                ->schema([
                    TextInput::make('card')->label('Scoate din uz / repune un card (număr sau cod de bare)')->placeholder('ex. 7 sau AWC-007'),
                ])
                ->modalSubmitActionLabel('Schimbă starea cardului')
                ->action(function (array $data) {
                    $input = trim((string) ($data['card'] ?? ''));
                    $card = ctype_digit($input) && strlen($input) <= 3
                        ? WaiverCard::query()->where('number', (int) $input)->first()
                        : app(WaiverCodeService::class)->findCard($input);
                    if (! $card) {
                        Notification::make()->danger()->title('Cardul nu există.')->send();

                        return;
                    }
                    $card->forceFill(['is_active' => ! $card->is_active])->save();
                    if (! $card->is_active) {
                        $card->assignments()->active()->get()->each(fn ($code) => app(WaiverCodeService::class)->revoke($code, auth()->id()));
                    }
                    app(AuditLogger::class)->log($card->is_active ? 'waiver.card_enabled' : 'waiver.card_disabled', null, ['card' => $card->serial]);
                    Notification::make()->success()->title($card->label().($card->is_active ? ' a fost repus în uz.' : ' a fost scos din uz (pierdut sau deteriorat); codul lui nu mai funcționează.'))->send();
                }),
            Action::make('tablet')->label('Deschide pagina tabletei')->icon('heroicon-o-device-tablet')->color('gray')
                ->url(fn () => lroute('waiver.tablet', [], 'ro'))->openUrlInNewTab(),
        ];
    }

    private static function cardsTable(): string
    {
        $rows = WaiverCard::query()->orderBy('number')->get()->map(function (WaiverCard $card) {
            $current = $card->currentAssignment();
            $state = ! $card->is_active ? '<span style="color:#e11d48">scos din uz</span>'
                : ($current ? 'la grupul '.e($current->booking?->code ?? 'fără rezervare').' până la '.e(LocalTime::format($current->expires_at, 'H:i')) : '<span style="color:#16a34a">liber, la recepție</span>');

            return '<tr><td style="padding:.25rem .5rem;font-weight:600">'.e($card->label()).'</td><td style="padding:.25rem .5rem">'.e($card->serial).'</td><td style="padding:.25rem .5rem">'.$state.'</td></tr>';
        })->implode('');

        return '<table style="width:100%;font-size:.9rem;border-collapse:collapse"><thead><tr style="text-align:left;opacity:.7"><th style="padding:.25rem .5rem">Card</th><th style="padding:.25rem .5rem">Serie</th><th style="padding:.25rem .5rem">Unde e</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }
}
