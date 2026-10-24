<?php

namespace App\Filament\Resources\Users;

use App\Filament\Concerns\ChecksPermissions;
use App\Models\User;
use App\Modules\Audit\AuditLogger;
use App\Modules\Core\Access\Permissions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Conturile personalului. Parolele nu se afișează și nu se setează de administrator: utilizatorul primește
 * un link de setare a parolei. MFA este obligatoriu pentru rolurile cu acces la date financiare și marketing.
 */
class UserResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = User::class;

    protected static string $viewPermission = 'users.manage';

    protected static ?string $deletePermission = null;

    protected static string|\UnitEnum|null $navigationGroup = 'Sistem';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $modelLabel = 'utilizator';

    protected static ?string $pluralModelLabel = 'Utilizatori și roluri';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('name')->label('Nume')->required()->maxLength(120),
                TextInput::make('email')->label('E-mail')->email()->required()->unique(ignoreRecord: true)->maxLength(190),
                TextInput::make('phone')->label('Telefon')->tel()->maxLength(30),
                Toggle::make('is_active')->label('Cont activ')->default(true)->inline(false)
                    ->disabled(fn (?User $record) => $record?->is(auth()->user())),
            ]),
            CheckboxList::make('roles')->label('Roluri')->relationship('roles', 'name')
                ->getOptionLabelFromRecordUsing(fn ($record) => (Permissions::ROLES[$record->name]['label'] ?? $record->name).(in_array($record->name, User::MFA_REQUIRED_ROLES, true) ? ' (MFA obligatoriu)' : ''))
                ->disableOptionWhen(fn (string $value) => ! auth()->user()?->hasRole('super_admin') && DB::table('roles')->where('id', $value)->value('name') === 'super_admin')
                ->columns(2)->required(),
            TextInput::make('password')->label('Parolă inițială (opțional)')->password()->revealable()->visibleOn('create')
                ->rule(PasswordRule::min(12)->mixedCase()->numbers()->uncompromised())
                ->helperText('Recomandat: lasă gol — utilizatorul primește pe e-mail un link pentru a-și seta singur parola.'),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nume')->searchable()->description(fn (User $record) => $record->email),
                TextColumn::make('roles.name')->label('Roluri')->badge()->formatStateUsing(fn ($state) => Permissions::ROLES[$state]['label'] ?? $state),
                IconColumn::make('mfa')->label('MFA')->state(fn (User $record) => MultiFactorChallenge::make()->hasEnabledProviders($record))->boolean(),
                IconColumn::make('is_active')->label('Activ')->boolean(),
                TextColumn::make('last_login_at')->label('Ultima autentificare')->since()->placeholder('Niciodată'),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    Action::make('reset_link')->label('Trimite link de resetare parolă')->icon('heroicon-o-key')->color('gray')->requiresConfirmation()
                        ->action(function (User $record) {
                            $status = Password::broker()->sendResetLink(['email' => $record->email]);
                            app(AuditLogger::class)->log('user.password_reset_sent', $record);
                            Notification::make()->{$status === Password::RESET_LINK_SENT ? 'success' : 'warning'}()->title(__($status))->send();
                        }),
                    Action::make('reset_mfa')->label('Resetează MFA')->icon('heroicon-o-device-phone-mobile')->color('warning')->requiresConfirmation()
                        ->modalDescription('Utilizatorul va trebui să configureze din nou autentificarea în doi pași la următoarea conectare. Folosește doar după verificarea identității.')
                        ->visible(fn (User $record) => ! $record->is(auth()->user()))
                        ->action(function (User $record) {
                            $record->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null, 'has_email_authentication' => false])->save();
                            app(AuditLogger::class)->log('user.mfa_reset', $record);
                            Notification::make()->success()->title('MFA a fost resetat.')->send();
                        }),
                    Action::make('logout_everywhere')->label('Închide sesiunile')->icon('heroicon-o-arrow-right-start-on-rectangle')->color('danger')->requiresConfirmation()
                        ->action(function (User $record) {
                            $count = DB::table('sessions')->where('user_id', $record->id)->delete();
                            $record->forceFill(['remember_token' => null])->save();
                            app(AuditLogger::class)->log('user.sessions_revoked', $record, ['count' => $count]);
                            Notification::make()->success()->title("{$count} sesiuni închise.")->send();
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
