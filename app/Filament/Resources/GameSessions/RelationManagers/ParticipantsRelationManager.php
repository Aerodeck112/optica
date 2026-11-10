<?php

namespace App\Filament\Resources\GameSessions\RelationManagers;

use App\Filament\Resources\Players\PlayerResource;
use App\Modules\Arena\Models\GameSession;
use App\Modules\Arena\Models\Player;
use App\Modules\Arena\Models\SessionParticipant;
use App\Modules\Arena\Services\AvatarCatalog;
use App\Modules\Arena\Services\GameSessionService;
use App\Modules\Arena\Services\NicknamePolicy;
use App\Modules\Arena\Services\PlayerException;
use App\Modules\Arena\Services\PlayerService;
use App\Modules\Booking\Models\PlayerWaiver;
use App\Modules\Display\Services\DisplayStateBuilder;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Participanții sesiunii. Organizatorul nu trebuie să știe nickname-urile la rezervare: recepția îi adaugă aici,
 * din declarațiile semnate sau ca jucători noi, iar nickname-ul se completează cel târziu înainte de activare.
 */
class ParticipantsRelationManager extends RelationManager
{
    protected static string $relationship = 'participants';

    protected static ?string $title = 'Participanți';

    public function isReadOnly(): bool
    {
        return ! auth()->user()?->can('sessions.operate');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    private function session(): GameSession
    {
        return $this->getOwnerRecord();
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query) => $query->with('player'))->defaultSort('slot')->paginated(false)
            ->columns([
                TextColumn::make('slot')->label('Loc'),
                ImageColumn::make('avatar')->label('')->state(fn (SessionParticipant $p) => url(AvatarCatalog::url($p->player->avatar_key)))->circular()->imageSize(40),
                TextColumn::make('player.nickname')->label('Nickname')->weight('bold')->placeholder('NICKNAME DE COMPLETAT')->color(fn (SessionParticipant $p) => $p->player->hasNickname() ? null : 'danger')
                    ->description(fn (SessionParticipant $p) => match ($p->player->nickname_status) {
                        'review' => 'De verificat', 'blocked' => 'Respins — alege altul', default => null
                    }),
                TextColumn::make('player.display_consent')->label('Pe ecrane')->badge()
                    ->formatStateUsing(fn ($state, SessionParticipant $p) => $p->player->isPublic() ? 'Nominal' : 'Ca „'.$p->alias().'”')
                    ->color(fn ($state, SessionParticipant $p) => $p->player->isPublic() ? 'success' : 'gray'),
                TextColumn::make('status')->label('Participare')->badge()->formatStateUsing(fn ($state) => SessionParticipant::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success', 'registered' => 'info', default => 'gray'
                    }),
                IconColumn::make('counts')->label('În clasament')->boolean(),
                TextColumn::make('result')->label('Rezultat')->state(fn (SessionParticipant $p) => match (true) {
                    $p->time_ms !== null => DisplayStateBuilder::formatTime($p->time_ms),
                    $p->score !== null => ($p->team ? $p->team.' · ' : '').number_format($p->score, 0, ',', '.').' pct',
                    default => null,
                })->description(fn (SessionParticipant $p) => $p->result_source ? ($p->result_source === 'sync' ? 'sincronizat' : 'manual') : null)->placeholder('—'),
                TextColumn::make('place')->label('Poziție')->placeholder('—'),
                TextColumn::make('correction_note')->label('Corecție')->placeholder('')->wrap()->toggleable(),
            ])
            ->headerActions([
                $this->addExistingAction(),
                $this->addNewAction(),
                $this->fromWaiversAction(),
                Action::make('approveAll')->label('Aprobă nickname-urile de verificat')->icon('heroicon-o-check-badge')->color('success')
                    ->visible(fn () => auth()->user()?->can('players.moderate') && $this->session()->participants()->whereHas('player', fn ($q) => $q->where('nickname_status', 'review'))->exists())
                    ->requiresConfirmation()->modalDescription('Confirmi că nickname-urile marcate „de verificat” sunt potrivite pentru ecranele din arenă?')
                    ->action(function () {
                        foreach ($this->session()->participants()->with('player')->get() as $p) {
                            if ($p->player->nickname_status === 'review') {
                                $this->attempt(fn () => app(PlayerService::class)->approveNickname($p->player, auth()->id()));
                            }
                        }
                        Notification::make()->success()->title('Nickname-uri aprobate.')->send();
                    }),
            ])
            ->recordActions([
                Action::make('nickname')->label(fn (SessionParticipant $p) => $p->player->hasNickname() ? 'Nickname' : 'Completează nickname')->icon('heroicon-o-pencil-square')
                    ->color(fn (SessionParticipant $p) => $p->player->hasNickname() ? 'gray' : 'danger')
                    ->fillForm(fn (SessionParticipant $p) => ['nickname' => $p->player->nickname])
                    ->schema(fn (SessionParticipant $p) => [TextInput::make('nickname')->label('Nickname')->required()->maxLength(NicknamePolicy::MAX)
                        ->helperText('Schimbarea nu creează alt jucător: istoricul rămâne.')
                        ->rule(fn () => function (string $attribute, $value, Closure $fail) use ($p) {
                            if ($problem = app(NicknamePolicy::class)->problem($value, $p->player)) {
                                $fail($problem);
                            }
                        })])
                    ->action(fn (SessionParticipant $p, array $data) => $this->attempt(fn () => app(PlayerService::class)->changeNickname($p->player, $data['nickname'], 'staff', auth()->id()), 'Nickname salvat.')),
                Action::make('activate')->label('Activează')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (SessionParticipant $p) => $p->status === 'registered' && $this->session()->status !== 'completed')
                    ->action(fn (SessionParticipant $p) => $this->attempt(fn () => app(GameSessionService::class)->activate($p, auth()->id()), 'Participant activ.')),
                ActionGroup::make([
                    Action::make('avatar')->label('Schimbă avatarul')->icon('heroicon-o-face-smile')
                        ->fillForm(fn (SessionParticipant $p) => ['avatar_key' => $p->player->avatar_key])
                        ->schema([Select::make('avatar_key')->label('Avatar')->options(PlayerResource::avatarOptions())->allowHtml()->native(false)->searchable()->required()])
                        ->action(fn (SessionParticipant $p, array $data) => $this->attempt(fn () => app(PlayerService::class)->changeAvatar($p->player, $data['avatar_key'], auth()->id()), 'Avatar schimbat.')),
                    Action::make('consent')->label('Afișare pe ecrane')->icon('heroicon-o-tv')
                        ->fillForm(fn (SessionParticipant $p) => ['display_consent' => $p->player->display_consent, 'guardian' => $p->player->display_consent_guardian])
                        ->schema(fn (SessionParticipant $p) => [
                            Select::make('display_consent')->label('Nickname și avatar pe ecranele locației')->options(Player::CONSENTS)->required(),
                            TextInput::make('guardian')->label('Adultul care a decis (obligatoriu pentru minori)')->maxLength(120)->visible($p->player->is_minor),
                        ])
                        ->action(fn (SessionParticipant $p, array $data) => $this->attempt(fn () => app(PlayerService::class)->setDisplayConsent($p->player, $data['display_consent'], 'reception', $data['guardian'] ?? null, auth()->id()), 'Preferința de afișare a fost salvată.')),
                    $this->correctionAction('no_show', 'Nu s-a prezentat', 'heroicon-o-user-minus', ['status' => 'no_show']),
                    $this->correctionAction('remove', 'Scoate din sesiune', 'heroicon-o-x-mark', ['status' => 'removed']),
                    $this->correctionAction('restore', 'Repune ca activ', 'heroicon-o-arrow-uturn-left', ['status' => 'active'], fn (SessionParticipant $p) => in_array($p->status, ['removed', 'no_show'], true)),
                    $this->correctionAction('exclude', 'Exclude din clasament', 'heroicon-o-eye-slash', ['counts' => false], fn (SessionParticipant $p) => $p->counts),
                    $this->correctionAction('include', 'Include în clasament', 'heroicon-o-eye', ['counts' => true], fn (SessionParticipant $p) => ! $p->counts),
                ])->label('Mai mult'),
            ]);
    }

    private function correctionAction(string $name, string $label, string $icon, array $changes, ?Closure $visible = null): Action
    {
        return Action::make($name)->label($label)->icon($icon)
            ->visible(fn (SessionParticipant $p) => $visible ? $visible($p) : (isset($changes['status']) && $p->status !== $changes['status']))
            ->schema(fn () => $this->session()->status === 'completed'
                ? [Textarea::make('reason')->label('Motivul corecției (apare în jurnalul de audit)')->required()->maxLength(255)]
                : [Textarea::make('reason')->label('Notă (opțional)')->maxLength(255)])
            ->action(fn (SessionParticipant $p, array $data) => $this->attempt(fn () => app(GameSessionService::class)->correctParticipant($p, $changes, $data['reason'] ?? null, auth()->id()), 'Participarea a fost actualizată.'));
    }

    private function addExistingAction(): Action
    {
        return Action::make('addExisting')->label('Adaugă jucător existent')->icon('heroicon-o-user-plus')
            ->visible(fn () => $this->session()->status !== 'cancelled')
            ->schema(fn () => [
                Select::make('player_id')->label('Jucător (caută după nickname)')->required()->searchable()
                    ->options(fn () => $this->suggestedPlayers())
                    ->getSearchResultsUsing(fn (string $search) => Player::query()->where('nickname', 'like', "%{$search}%")->limit(25)->get()
                        ->mapWithKeys(fn (Player $p) => [$p->id => $p->label()])->all())
                    ->getOptionLabelUsing(fn ($value) => Player::query()->find($value)?->label())
                    ->helperText('Sugestii: jucătorii legați de clientul rezervării și cei din declarațiile semnate.'),
                Textarea::make('reason')->label('Motiv (doar pentru o sesiune deja finalizată)')->maxLength(255)->visible($this->session()->status === 'completed'),
            ])
            ->action(fn (array $data) => $this->attempt(fn () => app(GameSessionService::class)->addParticipant($this->session(), Player::query()->findOrFail($data['player_id']), auth()->id(), $data['reason'] ?? null), 'Jucător adăugat.'));
    }

    private function addNewAction(): Action
    {
        return Action::make('addNew')->label('Jucător nou')->icon('heroicon-o-plus')->color('primary')
            ->visible(fn () => in_array($this->session()->status, ['scheduled', 'checkin', 'live', 'paused'], true))
            ->modalDescription('Nickname-ul se poate completa și mai târziu, dar înainte de pornirea sesiunii.')
            ->schema(PlayerResource::fields(true))
            ->action(function (array $data) {
                $this->attempt(function () use ($data) {
                    $data['customer_id'] = $this->session()->booking?->customer_id;
                    $player = app(PlayerService::class)->create($data, 'staff', auth()->id());
                    app(GameSessionService::class)->addParticipant($this->session(), $player, auth()->id());
                }, 'Jucător creat și adăugat.');
            });
    }

    /** Jucătorii din declarațiile semnate pentru rezervare (cu nickname, avatar și preferința de afișare alese de ei). */
    private function fromWaiversAction(): Action
    {
        return Action::make('fromWaivers')->label('Adaugă din declarații')->icon('heroicon-o-document-check')->color('gray')
            ->visible(fn () => $this->session()->booking_id && in_array($this->session()->status, ['scheduled', 'checkin', 'live', 'paused'], true)
                && PlayerWaiver::query()->where('booking_id', $this->session()->booking_id)->exists())
            ->requiresConfirmation()->modalDescription('Se adaugă toți cei care au semnat declarația pentru această rezervare și nu sunt încă în sesiune.')
            ->action(function () {
                $added = 0;
                foreach (PlayerWaiver::query()->where('booking_id', $this->session()->booking_id)->orderBy('id')->get() as $waiver) {
                    $player = $waiver->player ?? app(PlayerService::class)->create([
                        'private_name' => $waiver->participant_name,
                        'is_minor' => $waiver->is_minor,
                        'customer_id' => $this->session()->booking?->customer_id,
                    ], 'staff', auth()->id());
                    if (! $waiver->player_id) {
                        $waiver->forceFill(['player_id' => $player->id])->save();
                    }
                    if (! $this->session()->participants()->where('player_id', $player->id)->exists()) {
                        $this->attempt(fn () => app(GameSessionService::class)->addParticipant($this->session(), $player, auth()->id()));
                        $added++;
                    }
                }
                Notification::make()->success()->title("Participanți adăugați: {$added}.")->send();
            });
    }

    /** @return array<int, string> */
    private function suggestedPlayers(): array
    {
        $session = $this->session();
        $in = $session->participants()->pluck('player_id')->all();
        $ids = [];
        if ($session->booking) {
            $ids = array_merge(
                Player::query()->where('customer_id', $session->booking->customer_id)->pluck('id')->all(),
                PlayerWaiver::query()->where('booking_id', $session->booking_id)->whereNotNull('player_id')->pluck('player_id')->all(),
            );
        }

        return Player::query()->whereIn('id', array_diff(array_unique($ids), $in))->get()->mapWithKeys(fn (Player $p) => [$p->id => $p->label()])->all();
    }

    private function attempt(Closure $do, ?string $done = null): void
    {
        try {
            $do();
            if ($done) {
                Notification::make()->success()->title($done)->send();
            }
        } catch (PlayerException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
        }
    }
}
