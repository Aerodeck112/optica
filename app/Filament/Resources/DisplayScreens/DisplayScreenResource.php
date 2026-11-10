<?php

namespace App\Filament\Resources\DisplayScreens;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Audit\AuditLogger;
use App\Modules\Booking\Models\Resource as ArenaResource;
use App\Modules\Display\Models\DisplayAsset;
use App\Modules\Display\Models\DisplayPlaylist;
use App\Modules\Display\Models\DisplayScreen;
use App\Modules\Display\Services\PairingService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Monitoarele din arenă: asociere dispozitiv (cod de unică folosință), revocare, reîncărcare, comenzi rapide
 * cu expirare și setările fiecărui ecran. „Online” = playerul a răspuns în ultimele 90 de secunde
 * (browserul nu poate confirma dacă panoul fizic al monitorului este aprins).
 */
class DisplayScreenResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = DisplayScreen::class;

    protected static string $viewPermission = 'display.control';

    protected static ?string $managePermission = 'display.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Ecrane afișare';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tv';

    protected static ?string $modelLabel = 'ecran';

    protected static ?string $pluralModelLabel = 'Ecrane';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ecran')->schema([
                Grid::make(3)->schema([
                    TextInput::make('name')->label('Denumire')->required()->maxLength(120),
                    Select::make('type')->label('Conținut')->options(DisplayScreen::TYPES)->required()->disabledOn('edit'),
                    TextInput::make('slug')->label('Adresă')->prefix('/display/')->required()->maxLength(60)->regex('/^[a-z0-9\-]{2,60}$/')->unique(ignoreRecord: true)
                        ->helperText('Litere mici, cifre și cratimă. Schimbarea adresei cere redeschiderea paginii pe PC.'),
                    Select::make('resource_id')->label('Arena / resursa')->options(fn () => ArenaResource::query()->orderBy('sort')->pluck('name', 'id'))
                        ->helperText('Live Arena arată doar sesiunile acestei resurse.'),
                    Select::make('display_playlist_id')->label('Playlist')->options(fn () => DisplayPlaylist::query()->pluck('name', 'id')),
                    Toggle::make('is_active')->label('Activ')->inline(false)->default(true),
                ]),
            ]),
            Section::make('Conținut')->schema([
                Grid::make(3)->schema([
                    TextInput::make('settings.title')->label('Titlu')->maxLength(40)->placeholder('Implicit: Live Arena / Hall of Legends'),
                    TextInput::make('settings.subtitle')->label('Subtitlu / explicația metricii')->maxLength(80)->placeholder('Jucătorii cu cele mai multe aventuri finalizate'),
                    Toggle::make('settings.show_clock')->label('Afișează ora și data')->inline(false)->default(true),
                    TextInput::make('settings.top_n')->label('Poziții pe prima pagină (Hall of Legends)')->numeric()->minValue(3)->maxValue(20)->placeholder('10'),
                    TextInput::make('settings.page_seconds')->label('Durata unei pagini (secunde)')->numeric()->minValue(6)->maxValue(60)->placeholder('12'),
                    Select::make('settings.period')->label('Perioada clasamentului')->options(['all' => 'General (din toate timpurile)', 'month' => 'Luna curentă', 'week' => 'Săptămâna curentă', 'custom' => 'Interval personalizat'])->live()->placeholder('General'),
                    DatePicker::make('settings.period_from')->label('De la')->visible(fn (Get $get) => $get('settings.period') === 'custom'),
                    DatePicker::make('settings.period_to')->label('Până la (inclusiv)')->visible(fn (Get $get) => $get('settings.period') === 'custom'),
                ]),
            ])->collapsible(),
            Section::make('Live și reclame')->schema([
                Grid::make(3)->schema([
                    Toggle::make('settings.intro')->label('Prezentare la începutul sesiunii')->inline(false)->default(true),
                    Toggle::make('settings.live_ads')->label('Reclame în timpul jocului')->inline(false)->default(true),
                    Toggle::make('settings.live_uninterrupted')->label('Live fără întreruperi')->inline(false)
                        ->helperText('Doar sesiunea live, fără reclame, cât timp jocul rulează.'),
                    TextInput::make('settings.live_ad_seconds')->label('Durata maximă a unei reclame în timpul jocului (s)')->numeric()->minValue(4)->maxValue(30)->placeholder('8'),
                    TextInput::make('settings.live_quiet_minutes')->label('Fără reclame în ultimele … minute')->numeric()->minValue(0)->maxValue(30)->placeholder('5'),
                ]),
            ])->collapsible(),
            Section::make('Aspect, sunet, QR, conexiune')->schema([
                Grid::make(3)->schema([
                    Select::make('settings.animation')->label('Intensitatea animațiilor')->options(['full' => 'Completă', 'reduced' => 'Redusă', 'minimal' => 'Minimă'])->placeholder('Completă'),
                    TextInput::make('settings.volume')->label('Volum video (0 = mut)')->numeric()->minValue(0)->maxValue(100)->placeholder('0')
                        ->helperText('Implicit mut. Browserul poate bloca sunetul la pornire.'),
                    TextInput::make('settings.offline_after_seconds')->label('Conținut local după … secunde fără conexiune')->numeric()->minValue(30)->maxValue(1800)->placeholder('120'),
                    TextInput::make('settings.qr_text')->label('Text lângă QR')->maxLength(40)->placeholder('Rezervă-ți aventura'),
                    TextInput::make('settings.qr_url')->label('Destinația QR')->url()->maxLength(255)->placeholder('Pagina de rezervare a site-ului'),
                ]),
            ])->collapsible(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id')->poll('20s')
            ->columns([
                TextColumn::make('name')->label('Ecran')->weight('bold')->description(fn (DisplayScreen $s) => url($s->publicPath())),
                TextColumn::make('type')->label('Conținut')->formatStateUsing(fn ($state) => $state === 'live_arena' ? 'Live Arena' : 'Hall of Legends'),
                TextColumn::make('resource.name')->label('Arena')->placeholder('Toate'),
                TextColumn::make('status')->label('Player')->badge()->state(fn (DisplayScreen $s) => match (true) {
                    ! $s->isPaired() => 'Neasociat',
                    $s->isOnline() => 'Online',
                    default => 'Nu răspunde',
                })->color(fn ($state) => match ($state) {
                    'Online' => 'success', 'Neasociat' => 'gray', default => 'danger'
                }),
                TextColumn::make('last_seen_at')->label('Ultima conexiune')->since()->placeholder('—'),
                TextColumn::make('last_data_at')->label('Ultimele date noi')->since()->placeholder('—'),
                TextColumn::make('player_version')->label('Versiune')->placeholder('—')->toggleable(),
                TextColumn::make('resolution')->label('Rezoluție')->placeholder('—')->toggleable(),
                TextColumn::make('override')->label('Comandă activă')->state(fn (DisplayScreen $s) => ($o = $s->activeOverride())
                    ? (DisplayScreen::COMMANDS[$o['command']] ?? $o['command']).' până la '.CarbonImmutable::parse($o['expires_at'])->setTimezone(config('aw.timezone'))->format('H:i') : null)->placeholder('Automat'),
            ])
            ->recordActions([
                Action::make('preview')->label('Previzualizare')->icon('heroicon-o-eye')->color('gray')
                    ->url(fn (DisplayScreen $s) => route('admin.display.preview', $s), shouldOpenInNewTab: true),
                self::quickAction(),
                Action::make('auto')->label('Revino la automat')->icon('heroicon-o-arrow-path-rounded-square')->color('gray')
                    ->visible(fn (DisplayScreen $s) => $s->activeOverride() !== null && self::userCan('display.control'))
                    ->action(function (DisplayScreen $s) {
                        $s->forceFill(['override' => null])->save();
                        app(AuditLogger::class)->log('display.override_cleared', $s);
                        Notification::make()->success()->title('Ecranul a revenit la playlistul automat.')->send();
                    }),
                ActionGroup::make([
                    Action::make('pair')->label('Asociază dispozitiv')->icon('heroicon-o-link')
                        ->visible(fn () => self::userCan('display.manage'))
                        ->requiresConfirmation()
                        ->modalDescription('Se generează un cod de activare valabil 30 de minute, de unică folosință. După activare, dispozitivul anterior (dacă există) pierde accesul.')
                        ->action(function (DisplayScreen $s) {
                            $code = app(PairingService::class)->issueCode($s, auth()->id());
                            Notification::make()->success()->persistent()
                                ->title("Cod de activare: {$code}")
                                ->body('Pe PC-ul monitorului deschide '.url($s->publicPath()).' și introdu codul. Valabil 30 de minute, o singură dată.')
                                ->send();
                        }),
                    Action::make('reload')->label('Reîncarcă playerul')->icon('heroicon-o-arrow-path')
                        ->visible(fn (DisplayScreen $s) => $s->isPaired() && self::userCan('display.control'))
                        ->action(function (DisplayScreen $s) {
                            $s->forceFill(['reload_requested_at' => now()])->save();
                            app(AuditLogger::class)->log('display.reload_requested', $s);
                            Notification::make()->success()->title('Playerul se reîncarcă la următoarea actualizare (câteva secunde).')->send();
                        }),
                    Action::make('revoke')->label('Revocă accesul dispozitivului')->icon('heroicon-o-no-symbol')->color('danger')
                        ->visible(fn (DisplayScreen $s) => $s->isPaired() && self::userCan('display.manage'))
                        ->requiresConfirmation()->modalDescription('PC-ul nu mai primește date; ecranul cere un cod nou de activare.')
                        ->action(function (DisplayScreen $s) {
                            app(PairingService::class)->revoke($s, auth()->id());
                            Notification::make()->success()->title('Acces revocat.')->send();
                        }),
                    Action::make('defaults')->label('Revino la setările implicite')->icon('heroicon-o-arrow-uturn-left')
                        ->visible(fn () => self::userCan('display.manage'))
                        ->requiresConfirmation()
                        ->action(function (DisplayScreen $s) {
                            $s->forceFill(['settings' => null])->save();
                            app(AuditLogger::class)->log('display.settings_reset', $s);
                            Notification::make()->success()->title('Setările ecranului au revenit la valorile implicite.')->send();
                        }),
                    EditAction::make()->label('Setări'),
                ])->label('Mai mult'),
            ]);
    }

    /** Comenzi rapide temporare: expiră singure, iar playlistul revine la automat. */
    public static function quickAction(): Action
    {
        return Action::make('quick')->label('Comandă rapidă')->icon('heroicon-o-bolt')->color('warning')
            ->visible(fn () => self::userCan('display.control'))
            ->schema(fn (DisplayScreen $s) => [
                Select::make('command')->label('Ce afișez')->required()->live()->options(array_filter(DisplayScreen::COMMANDS, fn ($label, $key) => ! ($s->type === 'hall_of_legends' && $key === 'live')
                    && ! ($s->type === 'live_arena' && $key === 'leaderboard'), ARRAY_FILTER_USE_BOTH)),
                Select::make('asset_id')->label('Material')->visible(fn (Get $get) => $get('command') === 'asset')->required(fn (Get $get) => $get('command') === 'asset')
                    ->options(fn () => DisplayAsset::query()->where('is_active', true)->get()->filter(fn ($a) => $a->appliesTo($s->type))->pluck('title', 'id')),
                Select::make('minutes')->label('Timp')->options([5 => '5 minute', 15 => '15 minute', 30 => '30 de minute', 60 => 'O oră', 180 => '3 ore'])->default(15)->required(),
                Text::make('După expirare, ecranul revine singur la playlistul automat.'),
            ])
            ->action(function (DisplayScreen $s, array $data) {
                $s->forceFill(['override' => [
                    'command' => $data['command'],
                    'asset_id' => $data['asset_id'] ?? null,
                    'expires_at' => now()->addMinutes((int) $data['minutes'])->toIso8601ZuluString(),
                    'by' => auth()->id(),
                ]])->save();
                app(AuditLogger::class)->log('display.override', $s, ['command' => $data['command'], 'minutes' => (int) $data['minutes']]);
                Notification::make()->success()->title('Comanda ajunge pe ecran în câteva secunde.')->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDisplayScreens::route('/'),
            'create' => Pages\CreateDisplayScreen::route('/create'),
            'edit' => Pages\EditDisplayScreen::route('/{record}/edit'),
        ];
    }
}
