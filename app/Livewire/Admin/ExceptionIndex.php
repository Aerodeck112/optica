<?php

namespace App\Livewire\Admin;

use App\Domain\Scheduling\AffectedAppointments;
use App\Models\Location;
use App\Models\ScheduleException;
use App\Models\Specialist;
use App\Support\Access\AccessControl;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Leaves, public holidays, closures and extra availability (LOC-02). Scope: one specialist or all,
 * one location or all. Global exceptions need access to every location.
 */
class ExceptionIndex extends Component
{
    #[Url]
    public string $period = 'upcoming';

    public ?int $editingId = null;

    public ?int $specialist_id = null;

    public ?int $location_id = null;

    public string $kind = 'unavailable';

    public string $reason = 'leave';

    public string $starts_on = '';

    public string $ends_on = '';

    public ?string $start_time = null;

    public ?string $end_time = null;

    public bool $online_enabled = false;

    public string $description = '';

    public function boot(): void
    {
        $this->authorize('schedules.manage');
    }

    public function mount(): void
    {
        $this->starts_on = $this->ends_on = CarbonImmutable::now(config('optica.clinic_timezone'))->toDateString();
    }

    public function edit(int $id): void
    {
        $e = $this->visible()->findOrFail($id);
        $this->editingId = $e->id;
        $this->specialist_id = $e->specialist_id;
        $this->location_id = $e->location_id;
        $this->kind = $e->kind;
        $this->reason = $e->reason;
        $this->starts_on = $e->starts_on->toDateString();
        $this->ends_on = $e->ends_on->toDateString();
        $this->start_time = $e->start_time ? substr($e->start_time, 0, 5) : null;
        $this->end_time = $e->end_time ? substr($e->end_time, 0, 5) : null;
        $this->online_enabled = (bool) $e->online_enabled;
        $this->description = (string) $e->description;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'specialist_id', 'location_id', 'kind', 'reason', 'start_time', 'end_time', 'online_enabled', 'description');
        $this->mount();
        $this->resetValidation();
    }

    public function save(AffectedAppointments $affected): void
    {
        $data = $this->validate([
            'specialist_id' => ['nullable', 'integer', Rule::exists('specialists', 'id')],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')],
            'kind' => ['required', Rule::in(['unavailable', 'available'])],
            'reason' => ['required', Rule::in(ScheduleException::REASONS)],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'start_time' => ['nullable', 'required_with:end_time', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_with:start_time', 'date_format:H:i', 'after:start_time'],
            'online_enabled' => ['boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        if ($data['kind'] === 'available' && (! $data['specialist_id'] || ! $data['location_id'] || ! $data['start_time'])) {
            $this->addError('kind', __('catalog.exceptions.errors.available_needs_scope'));

            return;
        }
        $this->authorizeScope($data['location_id']);

        $exception = $this->editingId ? $this->visible()->findOrFail($this->editingId) : new ScheduleException(['created_by' => auth()->id()]);
        $exception->fill([
            'specialist_id' => $data['specialist_id'],
            'location_id' => $data['location_id'],
            'kind' => $data['kind'],
            'reason' => $data['reason'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'start_time' => $data['start_time'] ?: null,
            'end_time' => $data['end_time'] ?: null,
            'online_enabled' => $data['kind'] === 'available' && $data['online_enabled'],
            'description' => $data['description'] ?: null,
        ])->save();

        $tz = config('optica.clinic_timezone');
        $count = $affected->find($data['specialist_id'], $data['location_id'],
            CarbonImmutable::parse($data['starts_on'], $tz)->max(CarbonImmutable::now()),
            CarbonImmutable::parse($data['ends_on'], $tz)->addDay())->count();
        app(AuditLogger::class)->log($this->editingId ? 'schedules.exception_updated' : 'schedules.exception_created', $exception, ['affected_appointments' => $count], locationId: $data['location_id']);

        session()->flash('status', $count ? trans_choice('catalog.schedules.saved_with_affected', $count, ['count' => $count]) : __('catalog.exceptions.saved'));
        $this->redirectRoute('admin.exceptions.index', navigate: true);
    }

    public function delete(int $id): void
    {
        $e = $this->visible()->findOrFail($id);
        $this->authorizeScope($e->location_id);
        app(AuditLogger::class)->log('schedules.exception_deleted', $e, locationId: $e->location_id);
        $e->delete();
    }

    private function authorizeScope(?int $locationId): void
    {
        $allowed = app(AccessControl::class)->locationIdsFor(auth()->user(), 'schedules.manage');
        abort_unless($allowed === null || ($locationId !== null && in_array($locationId, $allowed, true)), 403);
    }

    private function visible()
    {
        $allowed = app(AccessControl::class)->locationIdsFor(auth()->user(), 'schedules.manage');

        return ScheduleException::query()
            ->with(['specialist', 'location'])
            ->when($allowed !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('location_id')->orWhereIn('location_id', $allowed)));
    }

    public function render(AffectedAppointments $affected)
    {
        $today = CarbonImmutable::now(config('optica.clinic_timezone'))->toDateString();
        $allowed = app(AccessControl::class)->locationIdsFor(auth()->user(), 'schedules.manage');

        return view('livewire.admin.exception-index', [
            'exceptions' => $this->visible()
                ->when($this->period === 'upcoming', fn ($q) => $q->whereDate('ends_on', '>=', $today))
                ->when($this->period === 'past', fn ($q) => $q->whereDate('ends_on', '<', $today))
                ->orderBy('starts_on', $this->period === 'past' ? 'desc' : 'asc')
                ->limit(300)->get(),
            'specialists' => Specialist::orderBy('display_name')->get(),
            'locations' => Location::when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->orderBy('sort_order')->get(),
            'canGlobal' => $allowed === null,
            'affected' => $affected->find()->filter(fn ($a) => $allowed === null || in_array($a->location_id, $allowed, true)),
        ])->layout('components.layouts.crm')->title(__('catalog.exceptions.title'));
    }
}
