<?php

namespace App\Filament\Resources\GameSessions\Pages;

use App\Filament\Resources\GameSessions\GameSessionResource;
use App\Filament\Resources\PlayerWaivers\WaiverCodeActions;
use App\Modules\Arena\Models\GameSession;
use App\Modules\Arena\Models\SessionParticipant;
use App\Modules\Arena\Services\GameSessionService;
use App\Modules\Arena\Services\PlayerException;
use App\Modules\Display\Services\DisplayStateBuilder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Collection;

/**
 * Fișa sesiunii: operatorul asociază participanții, verifică nickname-urile, pornește, întrerupe, ajustează durata,
 * finalizează sau anulează sesiunea, și introduce rezultatele (dacă jocul are o regulă de clasament confirmată).
 */
class ViewGameSession extends ViewRecord
{
    protected static string $resource = GameSessionResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return 'Sesiune #'.$record->id.' — '.($record->game?->getTranslation('title', 'ro') ?? 'joc neales');
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->serviceAction('start', 'Pornește sesiunea', 'heroicon-o-play', 'danger', ['scheduled', 'checkin'],
                fn (GameSession $s) => app(GameSessionService::class)->start($s, auth()->id()), 'Sesiunea a pornit. Ecranul Live Arena prezintă echipa.')
                ->requiresConfirmation()->modalDescription('Toți participanții înregistrați trebuie să aibă nickname. Ecranul Live Arena va afișa echipa imediat.'),
            $this->serviceAction('pause', 'Pauză', 'heroicon-o-pause', 'warning', ['live'], fn (GameSession $s) => app(GameSessionService::class)->pause($s, auth()->id()), 'Sesiune în pauză.'),
            $this->serviceAction('resume', 'Reia', 'heroicon-o-play', 'success', ['paused'], fn (GameSession $s) => app(GameSessionService::class)->resume($s, auth()->id()), 'Sesiunea continuă.'),
            $this->serviceAction('finish', 'Finalizează', 'heroicon-o-flag', 'success', ['live', 'paused'],
                fn (GameSession $s) => app(GameSessionService::class)->finish($s, auth()->id()), 'Misiune încheiată. Participările s-au trecut în Hall of Legends.')
                ->requiresConfirmation()->modalDescription('Participanții activi primesc câte o aventură finalizată (o singură dată). Cei încă doar înregistrați se trec „neprezentare”.'),
            Action::make('duration')->label('Durată')->icon('heroicon-o-clock')->color('gray')
                ->visible(fn () => ! in_array($this->getRecord()->status, ['completed', 'cancelled'], true) && $this->can())
                ->fillForm(fn () => ['planned_minutes' => $this->getRecord()->planned_minutes])
                ->schema([TextInput::make('planned_minutes')->label('Durată planificată (minute)')->numeric()->minValue(5)->maxValue(240)->required()])
                ->action(fn (array $data) => $this->run(fn (GameSession $s) => app(GameSessionService::class)->adjustDuration($s, (int) $data['planned_minutes'], auth()->id()), 'Durata a fost actualizată.')),
            $this->resultsAction(),
            WaiverCodeActions::make(fn () => $this->getRecord()->booking, fn () => $this->getRecord())
                ->visible(fn () => WaiverCodeActions::canIssue() && ! in_array($this->getRecord()->status, ['completed', 'cancelled'], true)),
            ActionGroup::make([
                EditAction::make()->label('Detalii (joc, arenă, test)'),
                Action::make('cancel')->label('Anulează sesiunea')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn () => ! in_array($this->getRecord()->status, ['completed', 'cancelled'], true) && $this->can())
                    ->schema([Textarea::make('reason')->label('Motiv')->required()->maxLength(300)])
                    ->action(fn (array $data) => $this->run(fn (GameSession $s) => app(GameSessionService::class)->cancel($s, $data['reason'], auth()->id()), 'Sesiune anulată (nu se numără).')),
                Action::make('clearResults')->label('Șterge rezultatele')->icon('heroicon-o-trash')->color('danger')
                    ->visible(fn () => $this->getRecord()->results_source !== null && $this->can())
                    ->schema([Textarea::make('reason')->label('Motiv (corecție)')->required()->maxLength(300)])
                    ->action(fn (array $data) => $this->run(fn (GameSession $s) => app(GameSessionService::class)->clearResults($s, $data['reason'], auth()->id()), 'Rezultatele au fost șterse.')),
            ])->label('Mai mult')->button()->color('gray'),
        ];
    }

    private function can(): bool
    {
        return (bool) auth()->user()?->can('sessions.operate');
    }

    private function serviceAction(string $name, string $label, string $icon, string $color, array $statuses, \Closure $do, string $done): Action
    {
        return Action::make($name)->label($label)->icon($icon)->color($color)
            ->visible(fn () => in_array($this->getRecord()->status, $statuses, true) && $this->can())
            ->action(fn () => $this->run($do, $done));
    }

    private function run(\Closure $do, string $done): void
    {
        try {
            $do($this->getRecord());
            Notification::make()->success()->title($done)->send();
        } catch (PlayerException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
        }
        $this->refreshFormData([]);
        $this->record = $this->getRecord()->fresh();
        $this->dispatch('$refresh');
    }

    /** Rezultatele reale, validate după regula jocului; pozițiile se calculează automat (egalitate = aceeași poziție). */
    private function resultsAction(): Action
    {
        return Action::make('results')->label('Rezultate')->icon('heroicon-o-trophy')->color('primary')
            ->visible(fn () => $this->getRecord()->result_type !== 'none' && in_array($this->getRecord()->status, ['live', 'paused', 'completed'], true) && $this->can())
            ->modalHeading('Rezultate introduse manual')
            ->modalDescription(fn () => 'Regula: '.GameSession::RESULT_TYPES[$this->getRecord()->result_type].'. Introdu doar rezultate reale; ecranul arată podiumul numai când rezultatele există pentru toți participanții activi.')
            ->fillForm(function () {
                $rows = [];
                foreach ($this->participants() as $p) {
                    $rows[$p->id] = ['score' => $p->score, 'team' => $p->team, 'time' => $p->time_ms !== null ? DisplayStateBuilder::formatTime($p->time_ms) : null];
                }

                return ['rows' => $rows];
            })
            ->schema(function () {
                $type = $this->getRecord()->result_type;
                $fields = [];
                foreach ($this->participants() as $p) {
                    $label = 'Locul '.$p->slot.' · '.($p->player->nickname ?? 'fără nickname');
                    $row = match ($type) {
                        'time_low' => [TextInput::make("rows.{$p->id}.time")->label($label.' — timp (mm:ss.ms)')->required()->placeholder('12:34.5')],
                        'team_score' => [TextInput::make("rows.{$p->id}.team")->label($label.' — echipa')->required()->maxLength(20),
                            TextInput::make("rows.{$p->id}.score")->label('Scorul echipei')->required()->numeric()->minValue(0)],
                        default => [TextInput::make("rows.{$p->id}.score")->label($label.' — scor')->required()->numeric()->minValue(0)],
                    };
                    $fields[] = Grid::make(count($row))->schema($row);
                }

                return $fields ?: [Text::make('Sesiunea nu are participanți activi.')];
            })
            ->action(fn (array $data) => $this->run(fn (GameSession $s) => app(GameSessionService::class)->recordResults($s, (array) ($data['rows'] ?? []), 'manual', auth()->id()), 'Rezultatele au fost salvate.'));
    }

    /** @return Collection<int, SessionParticipant> */
    private function participants()
    {
        return $this->getRecord()->participants()->with('player')->where('status', 'active')->get();
    }
}
