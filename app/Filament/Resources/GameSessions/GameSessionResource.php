<?php

namespace App\Filament\Resources\GameSessions;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Arena\Models\GameSession;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\Resource as ArenaResource;
use App\Modules\Catalog\Models\Game;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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

/**
 * Sesiunile de joc efective. Ecranul Live Arena arată doar sesiunea pornită de operator (nu ora din rezervare).
 */
class GameSessionResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = GameSession::class;

    protected static string $viewPermission = 'sessions.operate';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Recepție';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-play-circle';

    protected static ?string $modelLabel = 'sesiune de joc';

    protected static ?string $pluralModelLabel = 'Sesiuni de joc';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        return GameSession::query()->whereIn('status', GameSession::RUNNING)->exists() ? 'LIVE' : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                Select::make('resource_id')->label('Arena / zona')->required()
                    ->options(fn () => ArenaResource::query()->where('is_active', true)->orderBy('sort')->pluck('name', 'id'))
                    ->default(fn () => ArenaResource::query()->where('type', 'arena')->whereNull('parent_id')->orderBy('id')->value('id')),
                Select::make('game_id')->label('Joc')->searchable()->live()
                    ->options(fn () => Game::query()->orderBy('sort')->get()->mapWithKeys(fn (Game $g) => [$g->id => $g->getTranslation('title', 'ro')]))
                    ->afterStateUpdated(fn ($state, $set) => $set('result_type', Game::query()->find($state)?->result_type ?: 'none')),
                Select::make('booking_id')->label('Rezervare (opțional)')->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Booking::query()->where('code', 'like', '%'.strtoupper($search).'%')->latest('starts_at')->limit(15)->get()
                        ->mapWithKeys(fn (Booking $b) => [$b->id => $b->code.' · '.($b->localStart() ?? 'fără oră')])->all())
                    ->getOptionLabelUsing(fn ($value) => Booking::query()->find($value)?->code),
                DateTimePicker::make('scheduled_at')->label('Ora programată')->seconds(false)->timezone(config('aw.timezone')),
                TextInput::make('planned_minutes')->label('Durată planificată (minute)')->numeric()->minValue(5)->maxValue(240)->default(60)->required(),
                Select::make('result_type')->label('Clasament în joc')->options(GameSession::RESULT_TYPES)->default('none')->required()
                    ->helperText('Doar dacă regula jocului este confirmată. „Fără clasament” = ecranul arată doar participanții.'),
            ]),
            Toggle::make('is_test')->label('Sesiune de test (nu se numără în Hall of Legends și nu apare la „Misiune încheiată”)'),
            Textarea::make('notes')->label('Note interne')->rows(2)->maxLength(2000),
        ])->columns(1);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Grid::make(4)->schema([
                    TextEntry::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => GameSession::STATUSES[$state] ?? $state)
                        ->color(fn ($state) => match ($state) {
                            'live' => 'danger', 'paused' => 'warning', 'completed' => 'success', 'cancelled' => 'gray', default => 'info'
                        }),
                    TextEntry::make('game.title')->label('Joc')->state(fn (GameSession $r) => $r->game?->getTranslation('title', 'ro'))->placeholder('Neales'),
                    TextEntry::make('resource.name')->label('Arena'),
                    TextEntry::make('booking.code')->label('Rezervare')->placeholder('—'),
                    TextEntry::make('scheduled_at')->label('Programată')->dateTime('d.m.Y H:i', config('aw.timezone'))->placeholder('—'),
                    TextEntry::make('started_at')->label('Pornită')->dateTime('d.m.Y H:i', config('aw.timezone'))->placeholder('—'),
                    TextEntry::make('planned_minutes')->label('Durată planificată')->suffix(' min'),
                    TextEntry::make('elapsed')->label('Timp de joc')->state(fn (GameSession $r) => $r->started_at ? intdiv($r->elapsedSeconds(), 60).' min' : '—'),
                    TextEntry::make('result_type')->label('Clasament')->formatStateUsing(fn ($state) => GameSession::RESULT_TYPES[$state] ?? $state),
                    TextEntry::make('results_source')->label('Rezultate')->formatStateUsing(fn ($state) => $state === 'sync' ? 'Sincronizate automat' : 'Introduse manual')->placeholder('Neintroduse'),
                    TextEntry::make('is_test')->label('Test')->formatStateUsing(fn ($state) => $state ? 'Da — nu se numără' : 'Nu'),
                    TextEntry::make('ended_at')->label('Încheiată')->dateTime('d.m.Y H:i', config('aw.timezone'))->placeholder('—'),
                ]),
                TextEntry::make('notes')->label('Note')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['game', 'resource', 'booking']))
            ->columns([
                TextColumn::make('status')->label('Stare')->badge()->formatStateUsing(fn ($state) => GameSession::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'live' => 'danger', 'paused' => 'warning', 'completed' => 'success', 'cancelled' => 'gray', default => 'info'
                    }),
                TextColumn::make('game.title')->label('Joc')->state(fn (GameSession $r) => $r->game?->getTranslation('title', 'ro'))->placeholder('Neales'),
                TextColumn::make('resource.name')->label('Arena'),
                TextColumn::make('scheduled_at')->label('Programată')->dateTime('d.m H:i', config('aw.timezone'))->placeholder('—'),
                TextColumn::make('started_at')->label('Pornită')->dateTime('d.m H:i', config('aw.timezone'))->placeholder('—'),
                TextColumn::make('participants_count')->label('Jucători activi')->counts(['participants' => fn (Builder $query) => $query->where('status', 'active')]),
                TextColumn::make('booking.code')->label('Rezervare')->placeholder('—'),
                TextColumn::make('is_test')->label('')->formatStateUsing(fn ($state) => $state ? 'TEST' : '')->badge()->color('gray'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stare')->options(GameSession::STATUSES),
                Filter::make('today')->label('Azi')->query(fn (Builder $q) => $q->where(fn ($w) => $w->whereDate('scheduled_at', today())->orWhereDate('started_at', today())->orWhereIn('status', GameSession::RUNNING))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\ParticipantsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGameSessions::route('/'),
            'create' => Pages\CreateGameSession::route('/create'),
            'view' => Pages\ViewGameSession::route('/{record}'),
            'edit' => Pages\EditGameSession::route('/{record}/edit'),
        ];
    }
}
