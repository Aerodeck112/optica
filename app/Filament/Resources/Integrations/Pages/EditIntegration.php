<?php

namespace App\Filament\Resources\Integrations\Pages;

use App\Filament\Resources\Integrations\IntegrationResource;
use App\Modules\Audit\AuditLogger;
use App\Modules\Core\Models\Integration;
use App\Modules\Integrations\IntegrationCatalog;
use App\Modules\Integrations\Services\IntegrationTester;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class EditIntegration extends EditRecord
{
    protected static string $resource = IntegrationResource::class;

    public function getTitle(): string
    {
        return IntegrationCatalog::get($this->getRecord()->key)['label'] ?? 'Integrare';
    }

    public function form(Schema $schema): Schema
    {
        /** @var Integration $record */
        $record = $this->getRecord();
        $definition = IntegrationCatalog::get($record->key) ?? ['drivers' => [], 'help' => ''];
        $stored = $record->decryptedCredentials();

        $sections = [];
        foreach ($definition['drivers'] as $driver => $config) {
            $fields = [];
            foreach ($config['credentials'] as $name => $field) {
                $input = TextInput::make("credentials.{$name}")->label($field['label'])->maxLength(2000);
                if ($field['secret'] ?? false) {
                    $input->password()->revealable(false)->autocomplete('new-password')
                        ->placeholder(! empty($stored[$name]) && $record->driver === $driver ? '•••••••• (setat — lasă gol pentru a păstra)' : 'De completat');
                }
                $fields[] = $input;
            }
            foreach ($config['options'] ?? [] as $name => $field) {
                $fields[] = TextInput::make("options.{$name}")->label($field['label'])->maxLength(255)->placeholder($field['default'] ?? null);
            }
            $sections[] = Section::make($config['label'])->schema([Grid::make(2)->schema($fields)])
                ->visible(fn (Get $get) => $get('driver') === $driver);
        }

        return $schema->components([
            Callout::make(IntegrationCatalog::STATUSES[$record->status] ?? $record->status)
                ->description(trim($definition['help'].($record->last_test_result ? ' Ultimul test: '.$record->last_test_result : '')))
                ->color(IntegrationResource::statusColor($record->status))
                ->icon('heroicon-o-information-circle'),
            Grid::make(2)->schema([
                Select::make('driver')->label('Furnizor')->options(collect($definition['drivers'])->map(fn ($d) => $d['label']))->live()->placeholder('Alege'),
                Select::make('mode')->label('Mod')->options(IntegrationCatalog::MODES)->required()
                    ->helperText('Pe mediile de dezvoltare și test, mesajele către clienți rămân în sandbox indiferent de mod.'),
            ]),
            ...$sections,
        ])->columns(1);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Integration $record */
        $record = $this->getRecord();
        $definition = IntegrationCatalog::get($record->key)['drivers'][$record->driver] ?? ['credentials' => []];
        $visible = [];
        foreach ($record->decryptedCredentials() as $name => $value) {
            // valorile secrete nu pleacă niciodată spre browser
            if (! ($definition['credentials'][$name]['secret'] ?? true)) {
                $visible[$name] = $value;
            }
        }

        return ['driver' => $record->driver, 'mode' => $record->mode, 'credentials' => $visible, 'options' => (array) $record->options];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Integration $record */
        $driverChanged = $record->driver !== ($data['driver'] ?? null);
        $credentials = $driverChanged ? [] : $record->decryptedCredentials();
        foreach ((array) ($data['credentials'] ?? []) as $name => $value) {
            if ($value !== null && $value !== '') {
                $credentials[$name] = trim((string) $value);
            }
        }
        $record->driver = $data['driver'] ?? null;
        $record->mode = $data['mode'];
        $record->options = array_filter((array) ($data['options'] ?? []), fn ($v) => $v !== null && $v !== '');
        $record->storeCredentials($credentials);
        if ($driverChanged || $record->isDirty(['credentials', 'mode'])) {
            $record->status = $record->credentials || $record->key === 'fiscal' ? 'adapter_ready' : 'unconfigured';
        }
        $record->updated_by = auth()->id();
        $record->save();
        app(AuditLogger::class)->log('integration.updated', $record, ['driver' => $record->driver, 'mode' => $record->mode, 'credential_keys' => array_keys($credentials)]);

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')->label('Testează conexiunea')->icon('heroicon-o-signal')
                ->action(function () {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $result = app(IntegrationTester::class)->test($this->getRecord()->refresh());
                    Notification::make()->{$result['ok'] ? 'success' : 'danger'}()->title($result['message'])->persistent()->send();
                    $this->redirect(IntegrationResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
            Action::make('anaf_authorize')->label('Autorizează la ANAF')->icon('heroicon-o-key')->color('gray')
                ->visible(fn () => $this->getRecord()->key === 'efactura' && $this->getRecord()->driver === 'anaf')
                ->url(fn () => route('admin.anaf.authorize'))
                ->tooltip('Se deschide pagina ANAF; este nevoie de certificatul digital al reprezentantului firmei.'),
            Action::make('verify')->label('Marchează verificat în producție')->icon('heroicon-o-shield-check')->color('success')
                ->visible(fn () => auth()->user()?->hasRole('super_admin') && $this->getRecord()->status === 'configured' && $this->getRecord()->mode === 'live')
                ->requiresConfirmation()
                ->modalDescription('Confirmă doar după o tranzacție reală reușită (ex. plată reală, e-mail primit de un client, factură acceptată de ANAF).')
                ->action(function () {
                    $this->getRecord()->forceFill(['status' => 'production_verified'])->save();
                    app(AuditLogger::class)->log('integration.production_verified', $this->getRecord());
                    Notification::make()->success()->title('Marcat ca verificat în producție.')->send();
                }),
            Action::make('block')->label('Blochează')->icon('heroicon-o-no-symbol')->color('danger')
                ->requiresConfirmation()->modalDescription('Integrarea se oprește imediat (ex. cheie compromisă). Mesajele trec în sandbox, plățile online devin indisponibile.')
                ->action(function () {
                    $this->getRecord()->forceFill(['status' => 'blocked', 'mode' => 'disabled'])->save();
                    app(AuditLogger::class)->log('integration.blocked', $this->getRecord());
                    $this->redirect(IntegrationResource::getUrl('edit', ['record' => $this->getRecord()]));
                }),
        ];
    }
}
