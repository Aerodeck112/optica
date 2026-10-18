<?php

namespace App\Livewire\Crm;

use App\Domain\Patients\PatientRegistry;
use App\Domain\Scheduling\BookingException;
use App\Domain\Scheduling\BookingService;
use App\Domain\Scheduling\Interval;
use App\Domain\Scheduling\WorkingWindows;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Location;
use App\Models\Offering;
use App\Models\Patient;
use App\Models\Specialist;
use App\Models\WaitlistEntry;
use App\Support\Access\AccessControl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Reception and specialist calendar (CAL-01, CAL-02). Every write goes through BookingService, so the
 * calendar, the public site and concurrent users share the same availability rules (PRG-02).
 */
class Calendar extends Component
{
    public const CANCEL_REASONS = ['patient_request', 'patient_no_answer', 'clinic_request', 'specialist_unavailable', 'duplicate', 'other'];

    public const PX_PER_MINUTE = 2;

    #[Url]
    public string $date = '';

    #[Url]
    public string $view = 'day';

    #[Url(as: 'loc')]
    public ?int $locationId = null;

    #[Url(as: 'sp')]
    public ?int $specialistId = null;

    #[Url(as: 'st')]
    public string $statusFilter = 'active';

    /** null | create | show | waitlist */
    public ?string $panel = null;

    public ?int $selectedId = null;

    public ?int $selectedVersion = null;

    public array $form = [];

    public string $patientQuery = '';

    public bool $newPatient = false;

    public array $reschedule = ['date' => '', 'time' => '', 'specialist_id' => null];

    public array $cancel = ['reason' => 'patient_request', 'note' => ''];

    public array $waitlist = [];

    public string $lastRefresh = '';

    public function boot(): void
    {
        $this->authorize('appointments.view');
    }

    public function mount(): void
    {
        $tz = config('optica.clinic_timezone');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)) {
            $this->date = CarbonImmutable::now($tz)->toDateString();
        }
        if (! in_array($this->view, ['day', 'week', 'month', 'agenda'], true)) {
            $this->view = 'day';
        }
        $allowed = $this->allowedLocations();
        if (! $this->locationId || ! $allowed->contains('id', $this->locationId)) {
            $this->locationId = $allowed->first()?->id;
        }
        $this->resetForm();
    }

    // ----- navigation -------------------------------------------------------------------------

    public function go(string $direction): void
    {
        $d = $this->day();
        $step = match ($this->view) {
            'week' => 7,
            'month' => 0,
            default => 1,
        };
        $d = match (true) {
            $direction === 'today' => CarbonImmutable::now(config('optica.clinic_timezone')),
            $this->view === 'month' => $direction === 'next' ? $d->addMonthNoOverflow() : $d->subMonthNoOverflow(),
            default => $direction === 'next' ? $d->addDays($step) : $d->subDays($step),
        };
        $this->date = $d->toDateString();
    }

    public function openDay(string $date): void
    {
        $this->date = $date;
        $this->view = 'day';
    }

    public function updatedLocationId(): void
    {
        $this->closePanel();
    }

    // ----- panels -----------------------------------------------------------------------------

    public function startCreate(int $specialistId, string $date, string $time): void
    {
        $this->authorizeLocation('appointments.create');
        $this->resetForm();
        $this->form['specialist_id'] = $specialistId;
        $this->form['date'] = $date;
        $this->form['time'] = $time;
        $this->form['service_id'] = $this->offeringsFor($specialistId)->first()?->service_id;
        $this->panel = 'create';
        $this->selectedId = null;
    }

    public function show(int $id): void
    {
        $appointment = $this->findAppointment($id);
        $this->selectedId = $appointment->id;
        $this->selectedVersion = $appointment->lock_version;
        $local = CarbonImmutable::instance($appointment->starts_at)->setTimezone(config('optica.clinic_timezone'));
        $this->reschedule = ['date' => $local->toDateString(), 'time' => $local->format('H:i'), 'specialist_id' => $appointment->specialist_id];
        $this->cancel = ['reason' => 'patient_request', 'note' => ''];
        $this->panel = 'show';
        $this->resetValidation();
    }

    public function openWaitlist(): void
    {
        $this->waitlist = [
            'patient_id' => null, 'contact_name' => '', 'contact_phone' => '', 'locale' => 'ro',
            'service_id' => null, 'specialist_id' => $this->specialistId,
            'preferred_from' => $this->date, 'preferred_to' => $this->day()->addDays(14)->toDateString(), 'notes' => '',
        ];
        $this->panel = 'waitlist';
        $this->selectedId = null;
    }

    public function closePanel(): void
    {
        $this->panel = null;
        $this->selectedId = null;
        $this->resetValidation();
    }

    public function choosePatient(int $id): void
    {
        $patient = Patient::findOrFail($id);
        $this->form['patient_id'] = $patient->id;
        $this->form['locale'] = $patient->locale;
        $this->patientQuery = '';
        $this->newPatient = false;
    }

    public function clearPatient(): void
    {
        $this->form['patient_id'] = null;
    }

    // ----- writes -----------------------------------------------------------------------------

    public function create(BookingService $booking, PatientRegistry $patients): void
    {
        $this->authorizeLocation('appointments.create');
        $rules = [
            'form.specialist_id' => ['required', 'integer'],
            'form.service_id' => ['required', 'integer'],
            'form.date' => ['required', 'date_format:Y-m-d'],
            'form.time' => ['required', 'date_format:H:i'],
            'form.locale' => ['required', Rule::in(config('optica.locales'))],
            'form.notes' => ['nullable', 'string', 'max:2000'],
            'form.outside_schedule' => ['boolean'],
        ];
        if ($this->newPatient) {
            $rules += [
                'form.last_name' => ['required', 'string', 'max:100'],
                'form.first_name' => ['required', 'string', 'max:100'],
                'form.phone' => ['required', 'string', 'max:32', 'regex:/^[0-9 +().-]{6,}$/'],
                'form.email' => ['nullable', 'email', 'max:190'],
            ];
        } elseif (! $this->form['patient_id']) {
            $rules['form.contact_name'] = ['required', 'string', 'max:200'];
            $rules['form.contact_phone'] = ['required', 'string', 'max:32', 'regex:/^[0-9 +().-]{6,}$/'];
        }
        $this->validate($rules);

        $offering = Offering::where([
            'location_id' => $this->locationId,
            'specialist_id' => $this->form['specialist_id'],
            'service_id' => $this->form['service_id'],
            'is_active' => true,
        ])->first();
        if (! $offering) {
            $this->addError('form.service_id', __('booking.errors.offering_inactive'));

            return;
        }
        $allowOutside = (bool) ($this->form['outside_schedule'] ?? false);
        if ($allowOutside && ! auth()->user()->can('schedules.manage', $this->locationId)) {
            $allowOutside = false;
        }

        $patientId = $this->form['patient_id'];
        if ($this->newPatient) {
            $patientId = $patients->create([
                'last_name' => $this->form['last_name'], 'first_name' => $this->form['first_name'],
                'phone' => $this->form['phone'], 'email' => $this->form['email'] ?: null, 'locale' => $this->form['locale'],
            ], auth()->user(), $this->locationId)->id;
        }

        try {
            $appointment = $booking->book($offering, [
                'starts_at' => CarbonImmutable::parse($this->form['date'].' '.$this->form['time'], config('optica.clinic_timezone')),
                'source' => AppointmentSource::Reception,
                'status' => AppointmentStatus::Confirmed,
                'patient_id' => $patientId,
                'contact_name' => $patientId ? null : $this->form['contact_name'],
                'contact_phone' => $patientId ? null : $this->form['contact_phone'],
                'locale' => $this->form['locale'],
                'admin_notes' => $this->form['notes'] ?: null,
                'idempotency_key' => $this->form['token'],
                'allow_outside_schedule' => $allowOutside,
                'user' => auth()->user(),
            ]);
        } catch (BookingException $e) {
            $this->addError('form.time', $e->getMessage());

            return;
        }

        $this->dispatch('notify', message: __('calendar.created'));
        $this->show($appointment->id);
    }

    public function moveTo(int $id, int $specialistId, string $date, string $time, BookingService $booking): void
    {
        $this->reschedule = ['date' => $date, 'time' => $time, 'specialist_id' => $specialistId];
        $this->selectedId = $id;
        $this->selectedVersion = $this->findAppointment($id)->lock_version;
        $this->applyReschedule($booking);
    }

    public function applyReschedule(BookingService $booking): void
    {
        $this->authorizeLocation('appointments.update');
        $this->validate([
            'reschedule.date' => ['required', 'date_format:Y-m-d'],
            'reschedule.time' => ['required', 'date_format:H:i'],
            'reschedule.specialist_id' => ['required', 'integer'],
        ]);
        $appointment = $this->findAppointment($this->selectedId);

        $offering = Offering::where([
            'location_id' => $appointment->location_id,
            'specialist_id' => $this->reschedule['specialist_id'],
            'service_id' => $appointment->service_id,
            'is_active' => true,
        ])->first();
        if (! $offering) {
            $this->addError('reschedule.specialist_id', __('booking.errors.offering_inactive'));

            return;
        }

        try {
            $booking->reschedule(
                $appointment,
                CarbonImmutable::parse($this->reschedule['date'].' '.$this->reschedule['time'], config('optica.clinic_timezone')),
                $offering,
                auth()->user(),
                $this->selectedVersion,
            );
        } catch (BookingException $e) {
            $this->addError('reschedule.time', $e->getMessage());
            $this->panel = 'show';

            return;
        }

        $this->date = $this->reschedule['date'];
        $this->dispatch('notify', message: __('calendar.rescheduled'));
        $this->show($appointment->id);
    }

    public function changeStatus(string $status, BookingService $booking): void
    {
        $this->authorizeLocation('appointments.change_status');
        $to = AppointmentStatus::tryFrom($status) ?? abort(422);
        try {
            $booking->changeStatus($this->findAppointment($this->selectedId), $to, auth()->user(), $this->selectedVersion);
        } catch (BookingException $e) {
            $this->addError('status', $e->getMessage());

            return;
        }
        $this->show($this->selectedId);
    }

    public function cancelAppointment(BookingService $booking): void
    {
        $this->authorizeLocation('appointments.cancel');
        $this->validate([
            'cancel.reason' => ['required', Rule::in(self::CANCEL_REASONS)],
            'cancel.note' => ['nullable', 'string', 'max:500', 'required_if:cancel.reason,other'],
        ]);
        try {
            $booking->cancel($this->findAppointment($this->selectedId), $this->cancel['reason'], auth()->user(), $this->cancel['note'] ?: null, $this->selectedVersion);
        } catch (BookingException $e) {
            $this->addError('cancel.reason', $e->getMessage());

            return;
        }
        $this->dispatch('notify', message: __('calendar.cancelled'));
        $this->show($this->selectedId);
    }

    public function addToWaitlist(): void
    {
        $this->authorizeLocation('appointments.create');
        $this->validate([
            'waitlist.patient_id' => ['nullable', 'integer', 'exists:patients,id'],
            'waitlist.contact_name' => ['required_without:waitlist.patient_id', 'nullable', 'string', 'max:200'],
            'waitlist.contact_phone' => ['required_without:waitlist.patient_id', 'nullable', 'string', 'max:32'],
            'waitlist.locale' => ['required', Rule::in(config('optica.locales'))],
            'waitlist.service_id' => ['nullable', 'integer', 'exists:services,id'],
            'waitlist.specialist_id' => ['nullable', 'integer', 'exists:specialists,id'],
            'waitlist.preferred_from' => ['required', 'date_format:Y-m-d'],
            'waitlist.preferred_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:waitlist.preferred_from'],
            'waitlist.notes' => ['nullable', 'string', 'max:500'],
        ]);
        WaitlistEntry::create(array_merge($this->waitlist, [
            'location_id' => $this->locationId,
            'created_by' => auth()->id(),
            'notes' => $this->waitlist['notes'] ?: null,
        ]));
        $this->dispatch('notify', message: __('calendar.waitlist.added'));
        $this->openWaitlist();
    }

    public function removeFromWaitlist(int $id): void
    {
        $this->authorizeLocation('appointments.update');
        WaitlistEntry::where('location_id', $this->locationId)->findOrFail($id)->update(['status' => 'removed']);
    }

    public function bookFromWaitlist(int $id): void
    {
        $entry = WaitlistEntry::where('location_id', $this->locationId)->findOrFail($id);
        $appointment = $this->selectedId ? $this->findAppointment($this->selectedId) : null;
        $local = $appointment ? CarbonImmutable::instance($appointment->starts_at)->setTimezone(config('optica.clinic_timezone')) : null;

        $this->resetForm();
        $this->form['specialist_id'] = $appointment?->specialist_id ?? $entry->specialist_id;
        $this->form['service_id'] = $entry->service_id ?? $appointment?->service_id;
        $this->form['date'] = $local?->toDateString() ?? $this->date;
        $this->form['time'] = $local?->format('H:i') ?? '';
        $this->form['patient_id'] = $entry->patient_id;
        $this->form['contact_name'] = (string) $entry->contact_name;
        $this->form['contact_phone'] = (string) $entry->contact_phone;
        $this->form['locale'] = $entry->locale;
        $this->form['waitlist_id'] = $entry->id;
        $this->panel = 'create';
    }

    // ----- helpers ----------------------------------------------------------------------------

    private function resetForm(): void
    {
        $this->form = [
            'token' => bin2hex(random_bytes(16)),
            'specialist_id' => null, 'service_id' => null, 'date' => $this->date, 'time' => '',
            'patient_id' => null, 'contact_name' => '', 'contact_phone' => '',
            'last_name' => '', 'first_name' => '', 'phone' => '', 'email' => '',
            'locale' => 'ro', 'notes' => '', 'outside_schedule' => false, 'waitlist_id' => null,
        ];
        $this->patientQuery = '';
        $this->newPatient = false;
    }

    private function day(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->date, config('optica.clinic_timezone'));
    }

    private function allowedLocations(): Collection
    {
        $ids = app(AccessControl::class)->locationIdsFor(auth()->user(), 'appointments.view');

        return Location::query()->where('is_active', true)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('sort_order')->get();
    }

    private function authorizeLocation(string $permission): void
    {
        abort_unless($this->locationId && auth()->user()->can($permission, $this->locationId), 403);
    }

    private function findAppointment(?int $id): Appointment
    {
        $appointment = Appointment::with(['patient', 'service', 'specialist', 'location', 'events.user', 'creator'])->findOrFail($id);
        abort_unless(auth()->user()->can('appointments.view', $appointment->location_id), 403);

        return $appointment;
    }

    private function offeringsFor(?int $specialistId): Collection
    {
        if (! $specialistId || ! $this->locationId) {
            return collect();
        }

        return Offering::with('service')
            ->where(['location_id' => $this->locationId, 'specialist_id' => $specialistId, 'is_active' => true])
            ->get()->sortBy(fn ($o) => $o->service->sort_order)->values();
    }

    /** Specialists shown as columns: working in the location that day, or with appointments there. */
    private function columns(CarbonImmutable $from, CarbonImmutable $to, Collection $appointments, WorkingWindows $windows): Collection
    {
        $specialists = Specialist::query()
            ->whereHas('locations', fn ($q) => $q->whereKey($this->locationId)->where('location_specialist.is_active', true))
            ->when($this->specialistId, fn ($q) => $q->whereKey($this->specialistId))
            ->orderBy('sort_order')->orderBy('display_name')
            ->get();

        return $specialists->map(function (Specialist $s) use ($from, $to, $windows, $appointments) {
            $w = $windows->forSpecialist($s->id, $this->locationId, $from, $to);

            return ['specialist' => $s, 'windows' => $w, 'has' => collect($w)->flatten()->isNotEmpty() || $appointments->contains('specialist_id', $s->id)];
        })->filter(fn ($c) => $this->specialistId || $c['has'])->values();
    }

    public function render(WorkingWindows $windows, PatientRegistry $patients)
    {
        $tz = config('optica.clinic_timezone');
        $day = $this->day();
        [$from, $to] = match ($this->view) {
            'week' => [$day->startOfWeek(CarbonImmutable::MONDAY), $day->startOfWeek(CarbonImmutable::MONDAY)->addDays(6)],
            'month' => [$day->startOfMonth(), $day->endOfMonth()->startOfDay()],
            default => [$day, $day],
        };

        $statuses = match ($this->statusFilter) {
            'all' => array_map(fn ($s) => $s->value, AppointmentStatus::cases()),
            'cancelled' => [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value, AppointmentStatus::Expired->value],
            default => array_diff(AppointmentStatus::blockingValues(), [AppointmentStatus::Held->value]),
        };

        $appointments = $this->locationId ? Appointment::query()
            ->with(['patient', 'service', 'specialist'])
            ->where('location_id', $this->locationId)
            ->whereIn('status', $statuses)
            ->where('starts_at', '>=', $from->utc())
            ->where('starts_at', '<', $to->addDay()->utc())
            ->when($this->specialistId, fn ($q) => $q->where('specialist_id', $this->specialistId))
            ->orderBy('starts_at')
            ->get() : collect();

        $columns = in_array($this->view, ['day', 'week'], true) && $this->locationId ? $this->columns($from, $to, $appointments, $windows) : collect();

        // The specialist's time in other locations is shown greyed, without patient data.
        $elsewhere = $columns->isNotEmpty() ? Appointment::query()->blocking()
            ->whereIn('specialist_id', $columns->pluck('specialist.id'))
            ->where('location_id', '!=', $this->locationId)
            ->where('starts_at', '>=', $from->utc())->where('starts_at', '<', $to->addDay()->utc())
            ->get(['id', 'specialist_id', 'location_id', 'block_starts_at', 'block_ends_at']) : collect();

        [$startHour, $endHour] = $this->hourRange($columns, $appointments);
        $selected = $this->selectedId && $this->panel === 'show' ? $this->findAppointment($this->selectedId) : null;

        $this->lastRefresh = CarbonImmutable::now($tz)->format('H:i:s');

        return view('livewire.crm.calendar', [
            'tz' => $tz,
            'day' => $day,
            'from' => $from,
            'to' => $to,
            'locations' => $this->allowedLocations(),
            'specialists' => Specialist::whereHas('locations', fn ($q) => $q->whereKey($this->locationId))->orderBy('display_name')->get(),
            'appointments' => $appointments,
            'columns' => $columns,
            'elsewhere' => $elsewhere,
            'startHour' => $startHour,
            'endHour' => $endHour,
            'selected' => $selected,
            'formOfferings' => $this->offeringsFor($this->form['specialist_id'] ?? null),
            'rescheduleSpecialists' => $selected ? Specialist::whereHas('offerings', fn ($q) => $q->where(['location_id' => $selected->location_id, 'service_id' => $selected->service_id, 'is_active' => true]))->get() : collect(),
            'patientResults' => $this->panel === 'create' && ! $this->newPatient && ! ($this->form['patient_id'] ?? null) ? $patients->search($this->patientQuery) : collect(),
            'chosenPatient' => ($this->form['patient_id'] ?? null) ? Patient::find($this->form['patient_id']) : null,
            'duplicates' => $this->newPatient && strlen((string) ($this->form['phone'] ?? '')) >= 6 ? $patients->possibleDuplicates($this->form) : collect(),
            'waitlistEntries' => $this->locationId ? WaitlistEntry::with(['patient', 'service', 'specialist'])->where('location_id', $this->locationId)->where('status', 'waiting')->orderBy('preferred_from')->get() : collect(),
            'monthCounts' => $this->view === 'month' ? $appointments->groupBy(fn ($a) => CarbonImmutable::instance($a->starts_at)->setTimezone($tz)->toDateString())->map->count() : collect(),
            'canCreate' => $this->locationId && auth()->user()->can('appointments.create', $this->locationId),
            'canUpdate' => $this->locationId && auth()->user()->can('appointments.update', $this->locationId),
            'canOverride' => $this->locationId && auth()->user()->can('schedules.manage', $this->locationId),
            'pollSeconds' => max(5, (int) config('optica.booking.calendar_poll_seconds')),
        ])->layout('components.layouts.crm')->title(__('calendar.title'));
    }

    /** @return array{0: int, 1: int} */
    private function hourRange(Collection $columns, Collection $appointments): array
    {
        $tz = config('optica.clinic_timezone');
        $start = 8;
        $end = 18;
        foreach ($columns as $c) {
            foreach (collect($c['windows'])->flatten() as $w) {
                /** @var Interval $w */
                $start = min($start, (int) $w->start->setTimezone($tz)->format('G'));
                $end = max($end, (int) ceil(((int) $w->end->setTimezone($tz)->format('G') * 60 + (int) $w->end->setTimezone($tz)->format('i')) / 60));
            }
        }
        foreach ($appointments as $a) {
            $s = CarbonImmutable::instance($a->starts_at)->setTimezone($tz);
            $e = CarbonImmutable::instance($a->ends_at)->setTimezone($tz);
            $start = min($start, (int) $s->format('G'));
            $end = max($end, (int) ceil(((int) $e->format('G') * 60 + (int) $e->format('i')) / 60));
        }

        return [max(0, $start), min(24, max($end, $start + 1))];
    }
}
