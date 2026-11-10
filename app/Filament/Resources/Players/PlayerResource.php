<?php

namespace App\Filament\Resources\Players;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Arena\Models\Player;
use App\Modules\Arena\Services\AvatarCatalog;
use App\Modules\Arena\Services\NicknamePolicy;
use App\Modules\Arena\Services\PlayerException;
use App\Modules\Arena\Services\PlayerService;
use App\Modules\Crm\Models\Customer;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Profilurile de jucător (distincte de clientul care rezervă). Nickname-ul se completează aici sau la check-in,
 * cel târziu înainte de activarea în sesiune. Profilurile nu se șterg: istoricul rămâne pe id-ul stabil.
 */
class PlayerResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Player::class;

    protected static string $viewPermission = 'players.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'CRM';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $modelLabel = 'jucător';

    protected static ?string $pluralModelLabel = 'Jucători (nickname și avatar)';

    protected static ?string $recordTitleAttribute = 'nickname';

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $count = Player::query()->where('nickname_status', 'review')->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Nickname-uri de verificat';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::fields())->columns(1);
    }

    /** Câmpurile profilului (folosite și la „Jucător nou” din sesiune). */
    public static function fields(bool $inSession = false): array
    {
        return [
            Section::make('Identitate publică (apare pe ecranele din arenă)')->schema([
                Grid::make(2)->schema([
                    TextInput::make('nickname')->label('Nickname')->maxLength(NicknamePolicy::MAX)
                        ->helperText('3–18 caractere: litere, cifre, spațiu, punct, cratimă, underscore. Nu folosi numele real. Dacă nu îl știi acum, îl completezi la check-in.')
                        ->rule(fn (?Player $record) => function (string $attribute, $value, Closure $fail) use ($record) {
                            if (filled($value) && ($problem = app(NicknamePolicy::class)->problem($value, $record))) {
                                $fail($problem);
                            }
                        }),
                    Select::make('avatar_key')->label('Avatar')->native(false)->searchable()->allowHtml()
                        ->options(self::avatarOptions())->placeholder('Atribuit automat')
                        ->helperText('Colecție originală Another World. Rămâne același la fiecare vizită.'),
                ]),
            ]),
            Section::make('Afișare pe ecranele locației')->schema([
                Text::make('Separată de acordul de marketing și de acordul foto. Fără acord, jucătorul apare în sesiune ca „Explorator 03”, cu avatar neutru, și nu apare în Hall of Legends; istoricul rămâne corect în CRM.'),
                Grid::make(3)->schema([
                    Toggle::make('is_minor')->label('Minor (sub 18 ani)')->live()->inline(false),
                    Select::make('display_consent')->label('Afișare nickname și avatar pe ecrane')->options(Player::CONSENTS)->default('unknown')->required()->live(),
                    TextInput::make('guardian')->label('Adultul care a decis (pentru minor)')->maxLength(120)
                        ->visible(fn (Get $get) => $get('is_minor') && $get('display_consent') === 'granted')
                        ->required(fn (Get $get) => $get('is_minor') && $get('display_consent') === 'granted'),
                ]),
            ]),
            Section::make('Legături (doar în CRM)')->schema([
                Grid::make(2)->schema([
                    Select::make('customer_id')->label('Client / organizator (adult responsabil)')->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Customer::query()->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                            ->limit(20)->get()->mapWithKeys(fn (Customer $c) => [$c->id => $c->name.($c->email ? " · {$c->email}" : '')])->all())
                        ->getOptionLabelUsing(fn ($value) => Customer::query()->find($value)?->name)
                        ->helperText('Cine a rezervat sau răspunde pentru jucător. Nu este obligatoriu același cu jucătorul.')
                        ->hidden($inSession),
                    TextInput::make('private_name')->label('Nume (intern, opțional)')->maxLength(120)
                        ->helperText('Nu apare niciodată pe ecrane. Se păstrează criptat.'),
                ]),
            ])->collapsible()->collapsed($inSession),
        ];
    }

    /** @return array<string, string> opțiuni cu miniatură (HTML static, fără date introduse de utilizatori) */
    public static function avatarOptions(): array
    {
        $options = [];
        foreach (AvatarCatalog::options() as $key => $label) {
            $options[$key] = '<span style="display:inline-flex;align-items:center;gap:.6rem"><img src="'.e(AvatarCatalog::url($key)).'" alt="" style="width:2rem;height:2rem;border-radius:50%">'.e($label).'</span>';
        }

        return $options;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('avatar')->label('')->state(fn (Player $p) => url(AvatarCatalog::url($p->avatar_key)))->circular()->imageSize(40),
                TextColumn::make('nickname')->label('Nickname')->searchable()->weight('bold')
                    ->placeholder('Nickname de completat')->description(fn (Player $p) => $p->nickname_status !== 'approved' ? Player::NICKNAME_STATUSES[$p->nickname_status] : null),
                TextColumn::make('nickname_status')->label('Stare')->badge()->formatStateUsing(fn ($state) => Player::NICKNAME_STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success', 'review' => 'warning', 'blocked' => 'danger', default => 'gray'
                    }),
                TextColumn::make('display_consent')->label('Pe ecrane')->badge()->formatStateUsing(fn ($state) => $state === 'granted' ? 'Da' : ($state === 'unknown' ? 'Neîntrebat' : 'Nu'))
                    ->color(fn ($state) => $state === 'granted' ? 'success' : 'gray'),
                TextColumn::make('is_minor')->label('Minor')->formatStateUsing(fn ($state) => $state ? 'Da' : '')->placeholder(''),
                TextColumn::make('customer.name')->label('Client / organizator')->placeholder('—')->toggleable(),
                TextColumn::make('participations_count')->label('Aventuri finalizate')->alignEnd()
                    ->counts(['participations' => fn (Builder $query) => $query->where('status', 'active')->where('counts', true)
                        ->whereHas('session', fn ($s) => $s->where('status', 'completed')->where('is_test', false))]),
                TextColumn::make('created_at')->label('Creat')->date('d.m.Y')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('nickname_status')->label('Nickname')->options(Player::NICKNAME_STATUSES),
                SelectFilter::make('display_consent')->label('Afișare pe ecrane')->options(Player::CONSENTS),
                TernaryFilter::make('is_minor')->label('Minori'),
            ])
            ->recordActions([
                self::approveAction(),
                self::blockAction(),
                EditAction::make(),
            ]);
    }

    public static function approveAction(): Action
    {
        return Action::make('approveNickname')->label('Aprobă nickname')->icon('heroicon-o-check-badge')->color('success')
            ->visible(fn (Player $record) => $record->nickname_status === 'review' && auth()->user()?->can('players.moderate'))
            ->action(function (Player $record) {
                try {
                    app(PlayerService::class)->approveNickname($record, auth()->id());
                    Notification::make()->success()->title('Nickname aprobat.')->send();
                } catch (PlayerException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function blockAction(): Action
    {
        return Action::make('blockNickname')->label('Respinge nickname')->icon('heroicon-o-no-symbol')->color('danger')
            ->visible(fn (Player $record) => filled($record->nickname) && auth()->user()?->can('players.moderate'))
            ->modalDescription('Nickname-ul dispare de pe ecrane, forma lui nu mai poate fi folosită de nimeni, iar profilul revine la „nickname de completat”. Istoricul jucătorului rămâne.')
            ->schema([Textarea::make('reason')->label('Motiv (intern)')->required()->maxLength(190)->rows(2)])
            ->action(function (Player $record, array $data) {
                app(PlayerService::class)->blockNickname($record, $data['reason'], auth()->id());
                Notification::make()->success()->title('Nickname respins și blocat.')->send();
            });
    }

    public static function getRelations(): array
    {
        return [RelationManagers\ParticipationsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlayers::route('/'),
            'create' => Pages\CreatePlayer::route('/create'),
            'edit' => Pages\EditPlayer::route('/{record}/edit'),
        ];
    }
}
