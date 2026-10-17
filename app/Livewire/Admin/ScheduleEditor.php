<?php

namespace App\Livewire\Admin;

use App\Domain\Scheduling\AffectedAppointments;
use App\Models\Location;
use App\Models\LocationSpecialist;
use App\Models\Offering;
use App\Models\Resource;
use App\Models\Schedule;
use App\Models\ScheduleSegment;
use App\Models\Specialist;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Working schedule of a specialist in a location (LOC-02): validity period, alternating weeks,
 * several segments per day, each with its slot grid, online switch, allowed services and room.
 */
class ScheduleEditor extends Component
{
    public ?Schedule $schedule = null;

    #[Url]
    public ?int $specialist_id = null;

    #[Url]
    public ?int $location_id = null;

    public string $name = '';

    public string $valid_from = '';

    public ?string $valid_to = null;

    public int $cycle_weeks = 1;

    public string $cycle_anchor_date = '';

    public bool $is_active = true;

    /** @var list<array<string, mixed>> */
    public array $segments = [];

    public function boot(): void
    {
        $this->authorize('schedules.manage');
    }

    public function mount(?Schedule $schedule = null): void
    {
        $tz = config('optica.clinic_timezone');
        $this->schedule = $schedule?->exists ? $schedule->fresh('segments') : null;

        if ($this->schedule) {
            $this->authorizeLocation($this->schedule->location_id);
            $this->specialist_id = $this->schedule->specialist_id;
            $this->location_id = $this->schedule->location_id;
            $this->name = $this->schedule->name;
            $this->valid_from = $this->schedule->valid_from->toDateString();
            $this->valid_to = $this->schedule->valid_to?->toDateString();
            $this->cycle_weeks = (int) $this->schedule->cycle_weeks;
            $this->cycle_anchor_date = $this->schedule->cycle_anchor_date->toDateString();
            $this->is_active = (bool) $this->schedule->is_active;
            $this->segments = $this->schedule->segments
                ->sortBy(fn ($s) => sprintf('%d-%d-%s', $s->cycle_week, $s->weekday, $s->start_time))
                ->map(fn (ScheduleSegment $s) => [
                    'cycle_week' => (int) $s->cycle_week,
                    'weekday' => (int) $s->weekday,
                    'segment' => $s->segment,
                    'start_time' => substr($s->start_time, 0, 5),
                    'end_time' => substr($s->end_time, 0, 5),
                    'slot_minutes' => $s->slot_minutes,
                    'online_enabled' => (bool) $s->online_enabled,
                    'service_ids' => array_map('intval', $s->service_ids ?? []),
                    'resource_id' => $s->resource_id,
                ])->values()->all();
        } else {
            $monday = CarbonImmutable::now($tz)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
            $this->valid_from = CarbonImmutable::now($tz)->toDateString();
            $this->cycle_anchor_date = $monday;
            $specialist = $this->specialist_id ? Specialist::find($this->specialist_id) : null;
            $this->name = $specialist ? $specialist->display_name : '';
        }
    }

    public function addSegment(int $weekday, int $cycleWeek = 1): void
    {
        $sameDay = collect($this->segments)->where('weekday', $weekday)->where('cycle_week', $cycleWeek);
        $used = $sameDay->pluck('segment')->all();
        $type = collect(ScheduleSegment::SEGMENTS)->first(fn ($s) => ! in_array($s, $used, true)) ?? 'extra_2';
        $last = $sameDay->sortBy('end_time')->last();

        $this->segments[] = [
            'cycle_week' => $cycleWeek,
            'weekday' => $weekday,
            'segment' => $type,
            'start_time' => $last['end_time'] ?? '09:00',
            'end_time' => $last ? min('23:59', CarbonImmutable::createFromFormat('H:i', $last['end_time'])->addHours(3)->format('H:i')) : '13:00',
            'slot_minutes' => 15,
            'online_enabled' => true,
            'service_ids' => [],
            'resource_id' => null,
        ];
    }

    public function copyDay(int $fromWeekday, int $cycleWeek = 1): void
    {
        $source = array_values(array_filter($this->segments, fn ($s) => $s['weekday'] === $fromWeekday && $s['cycle_week'] === $cycleWeek));
        foreach (range(1, 5) as $weekday) {
            if ($weekday === $fromWeekday) {
                continue;
            }
            $this->segments = array_values(array_filter($this->segments, fn ($s) => ! ($s['weekday'] === $weekday && $s['cycle_week'] === $cycleWeek)));
            foreach ($source as $segment) {
                $this->segments[] = ['weekday' => $weekday] + $segment;
            }
        }
    }

    public function removeSegment(int $index): void
    {
        unset($this->segments[$index]);
        $this->segments = array_values($this->segments);
    }

    protected function rules(): array
    {
        return [
            'specialist_id' => ['required', 'integer', Rule::exists('specialists', 'id')->whereNull('deleted_at')],
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:160'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'cycle_weeks' => ['required', 'integer', 'min:1', 'max:4'],
            'cycle_anchor_date' => ['required', 'date_format:Y-m-d'],
            'is_active' => ['boolean'],
            'segments' => ['array'],
            'segments.*.cycle_week' => ['required', 'integer', 'min:1', 'lte:cycle_weeks'],
            'segments.*.weekday' => ['required', 'integer', 'between:1,7'],
            'segments.*.segment' => ['required', Rule::in(ScheduleSegment::SEGMENTS)],
            'segments.*.start_time' => ['required', 'date_format:H:i'],
            'segments.*.end_time' => ['required', 'date_format:H:i'],
            'segments.*.slot_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
            'segments.*.online_enabled' => ['boolean'],
            'segments.*.service_ids' => ['array'],
            'segments.*.service_ids.*' => ['integer'],
            'segments.*.resource_id' => ['nullable', 'integer'],
        ];
    }

    public function save(AffectedAppointments $affected): void
    {
        $this->validate();
        $this->authorizeLocation($this->location_id);

        if (! LocationSpecialist::where(['location_id' => $this->location_id, 'specialist_id' => $this->specialist_id, 'is_active' => true])->exists()) {
            $this->addError('location_id', __('catalog.schedules.errors.not_linked'));

            return;
        }
        if (! $this->checkSegments()) {
            return;
        }

        $validResources = Resource::where('location_id', $this->location_id)->pluck('id')->all();
        $validServices = Offering::where(['location_id' => $this->location_id, 'specialist_id' => $this->specialist_id])->pluck('service_id')->all();

        $schedule = DB::transaction(function () use ($validResources, $validServices) {
            $schedule = $this->schedule ?? new Schedule;
            $schedule->fill([
                'specialist_id' => $this->specialist_id,
                'location_id' => $this->location_id,
                'name' => $this->name,
                'valid_from' => $this->valid_from,
                'valid_to' => $this->valid_to ?: null,
                'cycle_weeks' => $this->cycle_weeks,
                'cycle_anchor_date' => CarbonImmutable::parse($this->cycle_anchor_date)->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
                'is_active' => $this->is_active,
                'version' => ($this->schedule?->version ?? 0) + 1,
                'updated_by' => auth()->id(),
            ])->save();

            $schedule->segments()->delete();
            foreach ($this->segments as $s) {
                $services = array_values(array_intersect(array_map('intval', $s['service_ids'] ?? []), array_map('intval', $validServices)));
                $schedule->segments()->create([
                    'cycle_week' => $s['cycle_week'],
                    'weekday' => $s['weekday'],
                    'segment' => $s['segment'],
                    'start_time' => $s['start_time'],
                    'end_time' => $s['end_time'],
                    'slot_minutes' => $s['slot_minutes'] ?: null,
                    'online_enabled' => (bool) $s['online_enabled'],
                    'service_ids' => $services ?: null,
                    'resource_id' => in_array((int) $s['resource_id'], array_map('intval', $validResources), true) ? (int) $s['resource_id'] : null,
                ]);
            }

            return $schedule;
        });

        $affectedList = $affected->find($this->specialist_id, $this->location_id);
        app(AuditLogger::class)->log($this->schedule ? 'schedules.updated' : 'schedules.created', $schedule, [
            'segments' => count($this->segments),
            'affected_appointments' => $affectedList->count(),
        ], locationId: $this->location_id);

        session()->flash('status', $affectedList->isEmpty()
            ? __('catalog.schedules.saved')
            : trans_choice('catalog.schedules.saved_with_affected', $affectedList->count(), ['count' => $affectedList->count()]));
        $this->redirectRoute('admin.schedules.edit', $schedule, navigate: true);
    }

    public function delete(AffectedAppointments $affected): void
    {
        abort_unless($this->schedule, 404);
        $this->authorizeLocation($this->schedule->location_id);
        $schedule = $this->schedule;
        $schedule->delete();
        $count = $affected->find($schedule->specialist_id, $schedule->location_id)->count();
        app(AuditLogger::class)->log('schedules.deleted', $schedule, ['affected_appointments' => $count], locationId: $schedule->location_id);
        session()->flash('status', $count ? trans_choice('catalog.schedules.saved_with_affected', $count, ['count' => $count]) : __('crm.deleted'));
        $this->redirectRoute('admin.schedules.index', ['specialist' => $schedule->specialist_id], navigate: true);
    }

    /** End after start and no overlapping segments on the same day of the same cycle week. */
    private function checkSegments(): bool
    {
        $ok = true;
        foreach ($this->segments as $i => $s) {
            if ($s['end_time'] <= $s['start_time']) {
                $this->addError("segments.$i.end_time", __('catalog.schedules.errors.end_before_start'));
                $ok = false;
            }
            foreach ($this->segments as $j => $t) {
                if ($j <= $i || $s['weekday'] !== $t['weekday'] || $s['cycle_week'] !== $t['cycle_week']) {
                    continue;
                }
                if ($s['start_time'] < $t['end_time'] && $t['start_time'] < $s['end_time']) {
                    $this->addError("segments.$j.start_time", __('catalog.schedules.errors.overlap'));
                    $ok = false;
                }
            }
        }

        return $ok;
    }

    private function authorizeLocation(?int $locationId): void
    {
        abort_unless($locationId === null || auth()->user()->can('schedules.manage', $locationId), 403);
    }

    /** Segments of the specialist's other active schedules that overlap this one in time (other locations). */
    private function crossLocationWarnings(): array
    {
        if (! $this->specialist_id || ! $this->location_id) {
            return [];
        }
        $others = Schedule::with(['segments', 'location'])
            ->where('specialist_id', $this->specialist_id)
            ->where('location_id', '!=', $this->location_id)
            ->where('is_active', true)
            ->get()
            ->filter(fn ($o) => ($this->valid_to === null || $o->valid_from->toDateString() <= $this->valid_to)
                && ($o->valid_to === null || $o->valid_to->toDateString() >= $this->valid_from));

        $warnings = [];
        foreach ($others as $other) {
            foreach ($other->segments as $o) {
                foreach ($this->segments as $s) {
                    $sameWeek = $this->cycle_weeks === 1 || (int) $other->cycle_weeks === 1 || (int) $o->cycle_week === (int) $s['cycle_week'];
                    if ((int) $o->weekday === (int) $s['weekday'] && $sameWeek && $s['start_time'] < substr($o->end_time, 0, 5) && substr($o->start_time, 0, 5) < $s['end_time']) {
                        $warnings[] = __('catalog.schedules.cross_location_warning', [
                            'day' => __('crm.weekdays.'.$o->weekday),
                            'location' => $other->location->name(),
                            'from' => substr($o->start_time, 0, 5),
                            'to' => substr($o->end_time, 0, 5),
                        ]);
                    }
                }
            }
        }

        return array_values(array_unique($warnings));
    }

    public function render(AffectedAppointments $affected)
    {
        $specialists = Specialist::where('is_active', true)->orderBy('display_name')->get();
        $linkedLocations = $this->specialist_id
            ? Location::whereIn('id', LocationSpecialist::where(['specialist_id' => $this->specialist_id, 'is_active' => true])->pluck('location_id'))->orderBy('sort_order')->get()
                ->filter(fn ($l) => auth()->user()->can('schedules.manage', $l))
            : collect();

        $services = $this->location_id && $this->specialist_id
            ? Offering::with('service')->where(['location_id' => $this->location_id, 'specialist_id' => $this->specialist_id, 'is_active' => true])->get()->pluck('service')
            : collect();

        return view('livewire.admin.schedule-editor', [
            'specialists' => $specialists,
            'linkedLocations' => $linkedLocations,
            'services' => $services,
            'resources' => $this->location_id ? Resource::where('location_id', $this->location_id)->where('is_active', true)->get() : collect(),
            'warnings' => $this->crossLocationWarnings(),
            'affected' => $this->schedule ? $affected->find($this->schedule->specialist_id, $this->schedule->location_id) : collect(),
        ])->layout('components.layouts.crm')->title($this->schedule?->name ?? __('catalog.schedules.create'));
    }
}
