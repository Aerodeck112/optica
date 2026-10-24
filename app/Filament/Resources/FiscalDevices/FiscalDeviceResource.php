<?php

namespace App\Filament\Resources\FiscalDevices;

use App\Filament\Concerns\ChecksPermissions;
use App\Modules\Audit\AuditLogger;
use App\Modules\Finance\Models\FiscalDevice;
use App\Modules\Finance\Models\Payment;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class FiscalDeviceResource extends Resource
{
    use ChecksPermissions;

    public const DRIVERS = ['simulator' => 'Simulator (NU emite bonuri fiscale)', 'agent' => 'Agent local lângă casa de marcat'];

    protected static ?string $model = FiscalDevice::class;

    protected static string $viewPermission = 'fiscal.reconcile';

    protected static ?string $managePermission = 'settings.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Financiar';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-printer';

    protected static ?string $modelLabel = 'casă de marcat';

    protected static ?string $pluralModelLabel = 'Case de marcat';

    protected static ?int $navigationSort = 8;

    public static function canViewAny(): bool
    {
        return static::userCan('fiscal.reconcile') || static::userCan('settings.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Denumire')->required()->maxLength(120),
            Select::make('driver')->label('Mod de lucru')->options(self::DRIVERS)->required()->native()->default('agent')->live()
                ->helperText(fn ($get) => $get('driver') === 'simulator' ? 'Pentru instruire și teste. Înregistrările sunt marcate „SIMULARE – NU ESTE BON FISCAL”.' : 'Agentul se instalează pe calculatorul conectat la casa de marcat (vezi docs/casa-de-marcat.md).'),
            TextInput::make('model')->label('Model aparat')->maxLength(120)->placeholder('De completat'),
            TextInput::make('serial_number')->label('Serie fiscală')->maxLength(60)->placeholder('De completat'),
            KeyValue::make('vat_mapping')->label('Cote TVA → grupa fiscală a aparatului')->keyLabel('Cota (%)')->valueLabel('Grupa (A, B, C…)')
                ->helperText('Se stabilește cu service-ul autorizat al casei de marcat. Exemplu: 21 → A, 11 → B.')->columnSpanFull(),
            KeyValue::make('payment_mapping')->label('Metode de plată → cod în aparat')->keyLabel('Metoda')->valueLabel('Cod')
                ->default(['cash' => '', 'card_pos' => ''])->helperText('Metode: '.implode(', ', array_map(fn ($k, $v) => "{$k} ({$v})", array_keys(Payment::METHODS), Payment::METHODS)))->columnSpanFull(),
            Toggle::make('is_active')->label('Activă')->helperText('Un singur aparat activ e folosit implicit la recepție (aparatele reale au prioritate față de simulator).'),
            Placeholder::make('agent_key')->label('Cheie agent')->visibleOn('edit')
                ->content(fn (?FiscalDevice $record) => $record?->agent_key_id ? $record->agent_key_id.' (secretul nu se mai afișează; generați o cheie nouă dacă l-ați pierdut)' : 'Negenerată'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Denumire'),
                TextColumn::make('driver')->label('Mod')->badge()->formatStateUsing(fn ($state) => $state === 'simulator' ? 'Simulator' : 'Agent local')->color(fn ($state) => $state === 'simulator' ? 'warning' : 'info'),
                TextColumn::make('model')->label('Model')->placeholder('De completat'),
                TextColumn::make('last_seen_at')->label('Agent văzut')->since(config('aw.timezone'))->placeholder('niciodată')
                    ->color(fn ($state) => $state && now()->diffInMinutes($state, true) < 5 ? 'success' : 'danger'),
                IconColumn::make('is_active')->label('Activă')->boolean(),
            ])
            ->recordActions([EditAction::make(), self::keyAction()]);
    }

    /** Generează o cheie nouă pentru agent; secretul se afișează o singură dată. */
    public static function keyAction(): Action
    {
        return Action::make('agent_key')->label('Cheie agent')->icon('heroicon-o-key')->color('gray')
            ->visible(fn (FiscalDevice $record) => $record->driver === 'agent' && auth()->user()?->can('settings.manage'))
            ->requiresConfirmation()
            ->modalDescription('Se generează o cheie nouă; cheia anterioară (dacă exista) nu mai funcționează. Secretul se afișează o singură dată.')
            ->action(function (FiscalDevice $record) {
                $keyId = 'fa_'.Str::lower(Str::random(16));
                $secret = Str::random(48);
                $record->forceFill(['agent_key_id' => $keyId, 'agent_secret' => $secret, 'status' => 'key_issued'])->save();
                app(AuditLogger::class)->log('fiscal.agent_key_issued', $record, ['key_id' => $keyId]);
                Notification::make()->success()->persistent()->title('Cheia agentului')
                    ->body(new HtmlString('Copiați acum în configurarea agentului:<br>AW_AGENT_KEY=<code>'.e($keyId).'</code><br>AW_AGENT_SECRET=<code>'.e($secret).'</code><br>AW_SERVER='.e(url('/')).'<br><strong>Secretul nu va mai fi afișat.</strong>'))
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageFiscalDevices::route('/')];
    }
}
