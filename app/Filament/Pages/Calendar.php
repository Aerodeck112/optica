<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Support\ResourceBlockLabels;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\BookingResource as Occupancy;
use App\Modules\Booking\Models\Resource as ArenaResource;
use App\Modules\Booking\Models\ResourceBlock;
use App\Modules\Booking\Services\BookingException;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Booking\Services\ScheduleService;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Livewire\Attributes\Url;

/**
 * Calendarul arenei: zi (coloane pe resurse), săptămână și lună. Afișează rezervările care ocupă resurse,
 * blocările (mentenanță, evenimente interne) și cererile încă neconfirmate (care nu ocupă arena).
 */
class Calendar extends Page
{
    protected string $view = 'filament.pages.calendar';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|\UnitEnum|null $navigationGroup = 'Recepție';

    protected static ?string $navigationLabel = 'Calendar';

    protected static ?string $title = 'Calendar';

    protected static ?int $navigationSort = 1;

    #[Url]
    public string $mode = 'day';

    #[Url]
    public ?string $date = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('bookings.view');
    }

    public function mount(): void
    {
        $this->date ??= LocalTime::now()->toDateString();
        if (! in_array($this->mode, ['day', 'week', 'month'], true)) {
            $this->mode = 'day';
        }
    }

    public function setMode(string $mode): void
    {
        $this->mode = in_array($mode, ['day', 'week', 'month'], true) ? $mode : 'day';
    }

    public function shift(int $direction): void
    {
        $date = CarbonImmutable::parse($this->date);
        $next = match ($this->mode) {
            'week' => $date->addWeeks($direction),
            'month' => $date->addMonthsNoOverflow($direction),
            default => $date->addDays($direction),
        };
        $this->date = $next->toDateString();
    }

    public function today(): void
    {
        $this->date = LocalTime::now()->toDateString();
    }

    public function goToDay(string $date): void
    {
        $this->date = $date;
        $this->mode = 'day';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new_booking')->label('Rezervare nouă')->icon('heroicon-o-plus')
                ->url(BookingResource::getUrl('create'))
                ->visible(fn () => auth()->user()?->can('bookings.manage')),
            Action::make('block')->label('Blochează interval')->icon('heroicon-o-no-symbol')->color('gray')
                ->visible(fn () => auth()->user()?->can('resources.manage'))
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('resource_id')->label('Resursă')->options(fn () => ArenaResource::query()->where('is_active', true)->pluck('name', 'id'))->required(),
                        Select::make('type')->label('Tip')->options(ResourceBlockLabels::TYPES)->required()->default('maintenance'),
                        DatePicker::make('date')->label('Data')->required()->default(fn () => $this->date)->native(false)->displayFormat('d.m.Y'),
                        TextInput::make('reason')->label('Motiv (intern)')->maxLength(255),
                        TimePicker::make('from')->label('De la')->seconds(false)->required(),
                        TimePicker::make('until')->label('Până la')->seconds(false)->required()->after('from'),
                        Select::make('repeat')->label('Repetare')->options(['none' => 'O singură dată', 'weekly' => 'Săptămânal', 'daily' => 'Zilnic'])->default('none')->live(),
                        DatePicker::make('repeat_until')->label('Repetă până la')->native(false)->displayFormat('d.m.Y')
                            ->visible(fn ($get) => $get('repeat') !== 'none')->required(fn ($get) => $get('repeat') !== 'none')
                            ->maxDate(now()->addYear()),
                    ]),
                ])
                ->action(function (array $data) {
                    $intervals = [];
                    $day = CarbonImmutable::parse($data['date']);
                    $until = $data['repeat'] === 'none' ? $day : CarbonImmutable::parse($data['repeat_until']);
                    $step = $data['repeat'] === 'daily' ? 1 : 7;
                    for ($d = $day; $d->lessThanOrEqualTo($until) && count($intervals) < 400; $d = $d->addDays($step)) {
                        $start = LocalTime::fromLocal($d->toDateString(), substr($data['from'], 0, 5));
                        $end = LocalTime::fromLocal($d->toDateString(), substr($data['until'], 0, 5));
                        if ($start && $end && $end->greaterThan($start)) {
                            $intervals[] = [$start, $end];
                        }
                    }
                    try {
                        $blocks = app(BookingService::class)->blockResource(ArenaResource::query()->findOrFail($data['resource_id']), $intervals, $data['type'], $data['reason'] ?? null, auth()->id());
                        Notification::make()->success()->title(count($blocks).' interval(e) blocate.')->send();
                    } catch (BookingException $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                    }
                }),
        ];
    }

    public function removeBlock(int $id, bool $series = false): void
    {
        abort_unless(auth()->user()?->can('resources.manage'), 403);
        $block = ResourceBlock::query()->findOrFail($id);
        $query = $series && $block->series_id
            ? ResourceBlock::query()->where('series_id', $block->series_id)->where('starts_at', '>=', $block->starts_at)
            : ResourceBlock::query()->whereKey($block->id);
        $count = $query->delete();
        Notification::make()->success()->title("{$count} blocare(i) eliminate.")->send();
    }

    /** @return array{start: CarbonImmutable, end: CarbonImmutable, days: array<int, string>} */
    private function range(): array
    {
        $date = CarbonImmutable::parse($this->date);
        [$first, $last] = match ($this->mode) {
            'week' => [$date->startOfWeek(), $date->startOfWeek()->addDays(6)],
            'month' => [$date->startOfMonth()->startOfWeek(), $date->endOfMonth()->endOfWeek()],
            default => [$date, $date],
        };
        $days = [];
        for ($d = $first; $d->lessThanOrEqualTo($last); $d = $d->addDay()) {
            $days[] = $d->toDateString();
        }

        return [
            'start' => LocalTime::fromLocal($first->toDateString(), '00:00'),
            'end' => LocalTime::fromLocal($last->addDay()->toDateString(), '00:00'),
            'days' => $days,
        ];
    }

    protected function getViewData(): array
    {
        $range = $this->range();
        $resources = ArenaResource::query()->where('is_active', true)->orderBy('sort')->get();
        $schedule = app(ScheduleService::class);

        $occupancies = Occupancy::query()
            ->with(['booking.package'])
            ->where('is_active', true)
            ->where('starts_at', '<', $range['end'])->where('ends_at', '>', $range['start'])
            ->whereHas('booking', fn ($q) => $q->whereIn('status', Booking::OCCUPYING))
            ->get();
        $blocks = ResourceBlock::query()->where('starts_at', '<', $range['end'])->where('ends_at', '>', $range['start'])->get();

        $events = [];
        foreach ($occupancies as $occ) {
            $b = $occ->booking;
            $events[] = [
                'kind' => 'booking', 'status' => $b->status, 'resource_id' => $occ->resource_id,
                'start' => LocalTime::toLocal($b->starts_at ?? $occ->starts_at), 'end' => LocalTime::toLocal($b->ends_at ?? $occ->ends_at),
                'occ_start' => LocalTime::toLocal($occ->starts_at), 'occ_end' => LocalTime::toLocal($occ->ends_at),
                'title' => $b->contact_name.' · '.$b->participants.' pers.', 'subtitle' => $b->package?->getTranslation('name', 'ro').' · '.$b->code,
                'url' => BookingResource::getUrl('view', ['record' => $b]), 'id' => $b->id,
            ];
        }
        foreach ($blocks as $block) {
            $events[] = [
                'kind' => 'block', 'status' => 'block', 'resource_id' => $block->resource_id,
                'start' => LocalTime::toLocal($block->starts_at), 'end' => LocalTime::toLocal($block->ends_at),
                'occ_start' => LocalTime::toLocal($block->starts_at), 'occ_end' => LocalTime::toLocal($block->ends_at),
                'title' => ResourceBlockLabels::TYPES[$block->type] ?? $block->type, 'subtitle' => $block->reason,
                'url' => null, 'id' => $block->id, 'series' => (bool) $block->series_id,
            ];
        }
        usort($events, fn ($a, $b) => $a['start'] <=> $b['start']);

        $requests = Booking::query()->where('status', 'request')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhereBetween('starts_at', [$range['start'], $range['end']]))
            ->latest()->limit(20)->get();

        $windows = [];
        foreach ($range['days'] as $day) {
            $w = $schedule->windowFor($day);
            $windows[$day] = $w ? ['open' => LocalTime::toLocal($w['open']), 'close' => LocalTime::toLocal($w['close']), 'note' => $w['note'] ?? null] : null;
        }

        // intervalul orar afișat: de la cea mai devreme deschidere la cea mai târzie închidere (sau 09–22 dacă e închis)
        $minHour = 24;
        $maxHour = 0;
        foreach ($windows as $w) {
            if ($w) {
                $minHour = min($minHour, (int) $w['open']->format('G'));
                $maxHour = max($maxHour, (int) ceil(((int) $w['close']->format('G') * 60 + (int) $w['close']->format('i')) / 60) ?: 24);
            }
        }
        foreach ($events as $e) {
            if ($this->mode !== 'month' && in_array($e['start']->toDateString(), $range['days'], true)) {
                $minHour = min($minHour, (int) $e['occ_start']->format('G'));
                $maxHour = max($maxHour, min(24, (int) $e['occ_end']->format('G') + 1));
            }
        }
        if ($minHour >= $maxHour) {
            [$minHour, $maxHour] = [9, 22];
        }

        return [
            'resources' => $resources,
            'events' => $events,
            'requests' => $requests,
            'days' => $range['days'],
            'windows' => $windows,
            'minHour' => $minHour,
            'maxHour' => $maxHour,
            'current' => CarbonImmutable::parse($this->date),
            'todayDate' => LocalTime::now()->toDateString(),
            'canManageBlocks' => auth()->user()?->can('resources.manage'),
        ];
    }
}
