<?php

namespace App\Filament\Pages;

use App\Modules\Core\Settings\SettingsRepository;
use App\Modules\Core\Settings\SettingsSchema;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/**
 * Setările locației, generate din SettingsSchema. Câmpurile necompletate apar „De completat” și nu se afișează pe site.
 * Fiecare grup are propria permisiune; valorile secrete sunt criptate și nu sunt afișate niciodată în formular.
 */
class Settings extends Page
{
    protected string $view = 'filament.pages.form-page';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|\UnitEnum|null $navigationGroup = 'Configurare';

    protected static ?string $navigationLabel = 'Setări locație';

    protected static ?string $title = 'Setări locație';

    protected static ?int $navigationSort = -1;

    /** Cine poate modifica fiecare grup (pe lângă „settings.manage”, care le poate modifica pe toate). */
    public const GROUP_PERMISSIONS = [
        'booking' => 'resources.manage',
        'site' => 'cms.publish',
        'social' => 'cms.publish',
        'contact' => 'cms.publish',
        'venue' => 'cms.publish',
        'analytics' => 'marketing.manage',
        'marketing' => 'marketing.manage',
        'loyalty' => 'loyalty.manage',
        'ai' => 'ai.manage',
        'finance' => 'efactura.manage',
    ];

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return self::editableGroups() !== [];
    }

    public static function editableGroups(): array
    {
        $user = auth()->user();
        if (! $user) {
            return [];
        }

        return array_values(array_filter(array_keys(SettingsSchema::groups()), fn ($group) => $user->can('settings.manage') || (isset(self::GROUP_PERMISSIONS[$group]) && $user->can(self::GROUP_PERMISSIONS[$group]))));
    }

    private static function fieldKey(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public function mount(): void
    {
        $repo = app(SettingsRepository::class);
        $all = $repo->all();
        $state = [];
        foreach (SettingsSchema::definitions() as $key => $def) {
            if (! in_array($def['group'], self::editableGroups(), true)) {
                continue;
            }
            $value = $all[$key] ?? null;
            if (! empty($def['secret'])) {
                $value = null; // secretele nu se trimit niciodată în browser
            } elseif ($value === null && array_key_exists('default', $def) && in_array($def['type'], ['bool', 'int', 'multiselect', 'json'], true)) {
                $value = $def['default'];
            }
            $state[self::fieldKey($key)] = $def['type'] === 'bool' ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : $value;
        }
        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $repo = app(SettingsRepository::class);
        $missing = $repo->missingForLaunch();
        $tabs = [];
        foreach (SettingsSchema::groups() as $group => $label) {
            if (! in_array($group, self::editableGroups(), true)) {
                continue;
            }
            $fields = [];
            foreach (SettingsSchema::definitions() as $key => $def) {
                if ($def['group'] === $group) {
                    $fields[] = $this->field($key, $def, $repo);
                }
            }
            $missingInGroup = count(array_filter(array_keys($missing), fn ($k) => SettingsSchema::get($k)['group'] === $group));
            $tabs[] = Tab::make($label)->key('settings-'.$group)->badge($missingInGroup ?: null)->badgeColor('warning')->schema($fields)->columns(2);
        }

        return $schema->components([
            Callout::make($missing ? count($missing).' câmpuri obligatorii înainte de lansare sunt încă necompletate' : 'Toate câmpurile obligatorii pentru lansare sunt completate')
                ->description($missing ? implode(' · ', array_slice($missing, 0, 12)).(count($missing) > 12 ? ' …' : '') : null)
                ->color($missing ? 'warning' : 'success')
                ->icon($missing ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle'),
            Tabs::make('Setări')->tabs($tabs)->persistTabInQueryString(),
        ])->statePath('data');
    }

    private function field(string $key, array $def, SettingsRepository $repo)
    {
        $name = self::fieldKey($key);
        $label = $def['label'].(! empty($def['launch']) ? ' *' : '');
        $placeholder = 'De completat';
        $field = match ($def['type']) {
            'bool' => Toggle::make($name)->inline(false),
            'int' => TextInput::make($name)->numeric()->integer()->minValue(0)->placeholder($placeholder),
            'text' => Textarea::make($name)->rows(3)->maxLength(5000)->placeholder($placeholder)->columnSpanFull(),
            'select' => Select::make($name)->options(array_filter($def['options'] ?? [], fn ($k) => $k !== '', ARRAY_FILTER_USE_KEY))->placeholder($placeholder),
            'multiselect' => CheckboxList::make($name)->options($def['options'] ?? [])->columns(3),
            'json' => KeyValue::make($name)->keyLabel('Cheie')->valueLabel('Etichetă')->columnSpanFull(),
            'email' => TextInput::make($name)->email()->maxLength(190)->placeholder($placeholder),
            'url' => TextInput::make($name)->url()->maxLength(500)->placeholder($placeholder),
            'phone' => TextInput::make($name)->tel()->maxLength(30)->placeholder($placeholder),
            default => TextInput::make($name)->maxLength(500)->placeholder($placeholder),
        };
        if (! empty($def['secret'])) {
            $field = TextInput::make($name)->password()->revealable(false)->autocomplete('new-password')
                ->placeholder($repo->filled($key) ? '•••••• (setat — lasă gol pentru a-l păstra)' : 'De completat');
        }

        return $field->label($label)->helperText($def['help'] ?? null);
    }

    public function save(): void
    {
        $repo = app(SettingsRepository::class);
        $state = $this->form->getState();
        $groups = self::editableGroups();
        $changes = [];
        foreach (SettingsSchema::definitions() as $key => $def) {
            if (! in_array($def['group'], $groups, true)) {
                continue;
            }
            $name = self::fieldKey($key);
            if (! array_key_exists($name, $state)) {
                continue;
            }
            $value = $state[$name];
            if (! empty($def['secret']) && ($value === null || $value === '')) {
                continue; // păstrăm secretul existent
            }
            if ($def['type'] === 'bool') {
                $value = $value ? '1' : '0';
            }
            if (is_string($value)) {
                $value = trim($value);
            }
            $current = $repo->all()[$key] ?? null;
            if ($value !== $current && ! ($value === '' && $current === null) && ! ($value === null && $current === null)) {
                $changes[$key] = $value === '' ? null : $value;
            }
        }
        $repo->setMany($changes, auth()->id());
        Notification::make()->success()->title($changes ? count($changes).' setări salvate.' : 'Nicio modificare.')->send();
        $this->mount();
    }
}
