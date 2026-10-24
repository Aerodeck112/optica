<?php

namespace App\Filament\Pages;

use App\Modules\Core\Models\AppUpdate;
use App\Modules\Core\Updates\UpdateService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Actualizări ale aplicației încărcate ca arhivă .zip (doar super administrator). */
class Updates extends Page
{
    protected string $view = 'filament.pages.updates';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static string|\UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?string $navigationLabel = 'Actualizări';

    protected static ?string $title = 'Actualizări aplicație';

    protected static ?int $navigationSort = 90;

    public ?array $data = [];

    /** @var array<string, mixed>|null rezultatul verificării pachetului încărcat */
    public ?array $package = null;

    public ?string $packagePath = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('super_admin');
    }

    public function mount(): void
    {
        // după o actualizare pusă manual (File Manager), modificările bazei de date se aplică la deschiderea paginii
        Artisan::call('migrate', ['--force' => true]);
        if (str_contains(Artisan::output(), 'DONE')) {
            Notification::make()->success()->title('Baza de date a fost actualizată.')->send();
        }
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('archive')->label('Pachetul de actualizare (.zip)')
                ->disk('local')->directory('updates-incoming')->visibility('private')
                ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'application/octet-stream'])
                ->maxSize((int) config('aw.updates.max_upload_mb', 45) * 1024)
                ->required()
                ->helperText('Încărcați arhiva primită de la dezvoltator. Nimic nu se modifică până nu apăsați „Aplică actualizarea”.'),
        ])->statePath('data');
    }

    public function currentVersion(): string
    {
        return app(UpdateService::class)->currentVersion();
    }

    /** @return Collection<int, AppUpdate> */
    public function history(): Collection
    {
        return AppUpdate::query()->with('user')->latest('id')->limit(20)->get();
    }

    public function inspectAction(): Action
    {
        return Action::make('inspect')->label('Verifică pachetul')->icon('heroicon-o-magnifying-glass')
            ->action(function () {
                $state = $this->form->getState();
                $relative = is_array($state['archive']) ? reset($state['archive']) : $state['archive'];
                $path = Storage::disk('local')->path((string) $relative);
                try {
                    $package = app(UpdateService::class)->inspect($path);
                } catch (RuntimeException $e) {
                    Storage::disk('local')->delete((string) $relative);
                    $this->package = null;
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }
                $this->packagePath = (string) $relative;
                $this->package = [
                    'version' => $package['version'],
                    'description' => $package['description'],
                    'migrate' => $package['migrate'],
                    'files' => count($package['files']),
                    'delete' => count($package['delete']),
                    'sample' => array_slice(array_map(fn ($file) => $file['scope'].'/'.$file['path'], $package['files']), 0, 12),
                    'sha256' => $package['sha256'],
                ];
            });
    }

    public function applyAction(): Action
    {
        return Action::make('apply')->label('Aplică actualizarea')->icon('heroicon-o-arrow-down-tray')->color('success')
            ->visible(fn () => $this->package !== null)
            ->modalHeading(fn () => 'Aplici versiunea '.($this->package['version'] ?? '').'?')
            ->modalDescription('Se face întâi o copie de siguranță a bazei de date și a fișierelor înlocuite. Site-ul rămâne funcțional; operația durează de obicei sub un minut.')
            ->schema([TextInput::make('password')->label('Parola contului dumneavoastră (confirmare)')->password()->required()])
            ->action(function (array $data) {
                $user = auth()->user();
                if (! Hash::check((string) $data['password'], (string) $user->password)) {
                    Notification::make()->danger()->title('Parola nu este corectă.')->send();

                    return;
                }
                $path = Storage::disk('local')->path((string) $this->packagePath);
                try {
                    @set_time_limit(600);
                    $update = app(UpdateService::class)->apply($path, $user);
                    Notification::make()->success()->title('Actualizarea la versiunea '.$update->version.' a fost aplicată.')->body(implode(' ', (array) $update->log))->persistent()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                } finally {
                    Storage::disk('local')->delete((string) $this->packagePath);
                }
                // cache-urile tocmai au fost reconstruite: pagina se reîncarcă integral, nu se re-randează în aceeași cerere
                $this->redirect(static::getUrl());
            });
    }

    public function rollbackAction(): Action
    {
        return Action::make('rollback')->label('Revino la versiunea anterioară')->icon('heroicon-o-arrow-uturn-left')->color('danger')->size('sm')
            ->requiresConfirmation()
            ->modalDescription('Fișierele se readuc la starea dinaintea acestei actualizări. Baza de date nu se modifică (copia ei rămâne disponibilă).')
            ->action(function (array $arguments) {
                try {
                    $update = app(UpdateService::class)->rollback(AppUpdate::query()->findOrFail($arguments['update']), auth()->user());
                    Notification::make()->success()->title('Revenire făcută la versiunea '.$update->previous_version.'.')->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                }
                $this->redirect(static::getUrl());
            });
    }
}
