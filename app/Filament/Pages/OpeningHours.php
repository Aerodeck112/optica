<?php

namespace App\Filament\Pages;

use App\Modules\Audit\AuditLogger;
use App\Modules\Core\Models\OpeningHour;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;

/**
 * Programul săptămânal. Fără program completat, site-ul nu afișează ore și rezervările online rămân cereri.
 */
class OpeningHours extends Page
{
    protected string $view = 'filament.pages.form-page';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|\UnitEnum|null $navigationGroup = 'Configurare';

    protected static ?string $navigationLabel = 'Program săptămânal';

    protected static ?string $title = 'Program săptămânal';

    public const DAYS = [1 => 'Luni', 2 => 'Marți', 3 => 'Miercuri', 4 => 'Joi', 5 => 'Vineri', 6 => 'Sâmbătă', 7 => 'Duminică'];

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('resources.manage');
    }

    public function mount(): void
    {
        $rows = OpeningHour::query()->get()->keyBy('weekday');
        $state = [];
        foreach (self::DAYS as $day => $label) {
            $row = $rows[$day] ?? null;
            $state["d{$day}"] = [
                'is_closed' => (bool) ($row?->is_closed ?? true),
                'opens_at' => $row?->opens_at ? substr($row->opens_at, 0, 5) : null,
                'closes_at' => $row?->closes_at ? substr($row->closes_at, 0, 5) : null,
            ];
        }
        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $rows = [];
        foreach (self::DAYS as $day => $label) {
            $rows[] = Grid::make(3)->schema([
                Toggle::make("d{$day}.is_closed")->label($label.' — închis')->live()->inline(false),
                TimePicker::make("d{$day}.opens_at")->label('Deschidere')->seconds(false)->disabled(fn (Get $get) => $get("d{$day}.is_closed"))->required(fn (Get $get) => ! $get("d{$day}.is_closed")),
                TimePicker::make("d{$day}.closes_at")->label('Închidere')->seconds(false)->disabled(fn (Get $get) => $get("d{$day}.is_closed"))->required(fn (Get $get) => ! $get("d{$day}.is_closed"))
                    ->helperText('Ultima sesiune se termină cel târziu la această oră.'),
            ]);
        }

        return $schema->components([
            Section::make('Program pe zile')->description('Ora României. Zilele speciale (sărbători, program redus) se setează separat, în „Zile speciale”.')->schema($rows),
        ])->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        foreach (self::DAYS as $day => $label) {
            $d = $data["d{$day}"] ?? [];
            OpeningHour::query()->updateOrCreate(['weekday' => $day], [
                'is_closed' => (bool) ($d['is_closed'] ?? true),
                'opens_at' => $d['opens_at'] ?? null,
                'closes_at' => $d['closes_at'] ?? null,
            ]);
        }
        Cache::forget('aw.opening_hours');
        app(AuditLogger::class)->log('schedule.weekly_updated', null, ['hours' => $data]);
        Notification::make()->success()->title('Programul a fost salvat.')->send();
    }
}
