<?php

namespace App\Domain\Reports;

use App\Domain\Scheduling\WorkingWindows;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentEvent;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ServiceCharge;
use App\Models\User;
use App\Models\Visit;
use App\Support\Access\AccessControl;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Indicators with documented rules (RAP-03, RAP-04). Every number is computed once over the whole
 * filtered set: an appointment belongs to the location where the consultation takes place, and
 * unique patients are counted distinctly, never by adding per-location subtotals.
 */
class ReportEngine
{
    /** Temporary holds are never counted as appointments. */
    public const EXCLUDED = ['held'];

    /** Statuses that occupied time (occupancy). */
    public const OCCUPYING = ['pending', 'confirmed', 'arrived', 'in_consultation', 'completed', 'no_show'];

    /** Appointments that were due: the denominator of the no-show rate. */
    public const DUE = ['arrived', 'in_consultation', 'completed', 'no_show'];

    public function __construct(private readonly AccessControl $access, private readonly WorkingWindows $windows) {}

    /** Locations the user may report on (null = all). Managers see only their own (RAP-01). */
    public function allowedLocations(User $user, string $permission = 'reports.view'): ?array
    {
        return $this->access->locationIdsFor($user, $permission);
    }

    public function appointments(ReportFilters $f, User $user, bool $withStatusFilter = true): Builder
    {
        $allowed = $this->allowedLocations($user);
        $column = $f->basis === 'created' ? 'appointments.created_at' : 'appointments.starts_at';

        return Appointment::query()
            ->whereNotIn('appointments.status', self::EXCLUDED)
            ->whereBetween($column, $f->utcRange())
            ->when($allowed !== null, fn ($q) => $q->whereIn('appointments.location_id', $allowed))
            ->when($f->locationIds, fn ($q) => $q->whereIn('appointments.location_id', $f->locationIds))
            ->when($f->specialistIds, fn ($q) => $q->whereIn('appointments.specialist_id', $f->specialistIds))
            ->when($f->serviceIds, fn ($q) => $q->whereIn('appointments.service_id', $f->serviceIds))
            ->when($withStatusFilter && $f->statuses, fn ($q) => $q->whereIn('appointments.status', $f->statuses))
            ->when($f->sources, fn ($q) => $q->whereIn('appointments.source', $f->sources))
            ->when($f->locales, fn ($q) => $q->whereIn('appointments.locale', $f->locales))
            ->when($f->creators, function ($q) use ($f) {
                $q->where(function ($q) use ($f) {
                    if (in_array('patient', $f->creators, true)) {
                        $q->orWhereNull('appointments.created_by');
                    }
                    $ids = array_map('intval', array_filter($f->creators, 'is_numeric'));
                    if ($ids) {
                        $q->orWhereIn('appointments.created_by', $ids);
                    }
                });
            })
            // New = no earlier appointment of the same patient (any location); returning otherwise.
            ->when($f->patientType !== 'all', function ($q) use ($f) {
                $earlier = fn ($s) => $s->selectRaw('1')->from('appointments as prev')->whereColumn('prev.patient_id', 'appointments.patient_id')
                    ->whereColumn('prev.starts_at', '<', 'appointments.starts_at')->whereNotIn('prev.status', ['held', 'cancelled', 'expired']);
                $q->whereNotNull('appointments.patient_id');
                $f->patientType === 'new' ? $q->whereNotExists($earlier) : $q->whereExists($earlier);
            });
    }

    /** @return array<string, int|float|null> headline indicators */
    public function summary(ReportFilters $f, User $user): array
    {
        $base = $this->appointments($f, $user);
        $byStatus = (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);
        $total = $byStatus->sum();
        $cancelled = (int) ($byStatus['cancelled'] ?? 0);
        $noShow = (int) ($byStatus['no_show'] ?? 0);
        $due = $byStatus->only(self::DUE)->sum();

        $visits = $this->visits($f, $user);
        $wait = (clone $base)->whereNotIn('status', ['cancelled', 'expired'])
            ->selectRaw('avg(timestampdiff(minute, created_at, starts_at)) as avg_wait')->value('avg_wait');
        $duration = (clone $visits)->whereNotNull('ended_at')->selectRaw('avg(timestampdiff(minute, started_at, ended_at)) as d, count(*) as n')->first();

        return [
            'appointments' => $total,
            'by_status' => $byStatus->all(),
            'cancelled' => $cancelled,
            'cancel_rate' => $total ? round($cancelled / $total * 100, 1) : null,
            'no_show' => $noShow,
            'no_show_due' => $due,
            'no_show_rate' => $due ? round($noShow / $due * 100, 1) : null,
            'reschedules' => $this->reschedules($f, $user),
            'visits' => (clone $visits)->count(),
            'unique_patients' => (int) (clone $visits)->distinct()->count('visits.patient_id'),
            'unique_booked_patients' => (int) (clone $base)->whereNotNull('patient_id')->distinct()->count('appointments.patient_id'),
            'avg_wait_days' => $wait !== null ? round((float) $wait / 1440, 1) : null,
            'avg_visit_minutes' => $duration && $duration->n ? (int) round((float) $duration->d) : null,
            'visits_with_duration' => $duration ? (int) $duration->n : 0,
        ];
    }

    public function visits(ReportFilters $f, User $user): Builder
    {
        $allowed = $this->allowedLocations($user);

        return Visit::query()->whereBetween('visits.started_at', $f->utcRange())
            ->when($allowed !== null, fn ($q) => $q->whereIn('visits.location_id', $allowed))
            ->when($f->locationIds, fn ($q) => $q->whereIn('visits.location_id', $f->locationIds))
            ->when($f->specialistIds, fn ($q) => $q->whereIn('visits.specialist_id', $f->specialistIds))
            ->when($f->serviceIds, fn ($q) => $q->whereIn('visits.service_id', $f->serviceIds));
    }

    /** Reschedule events in the period, separate from appointments and patients. */
    public function reschedules(ReportFilters $f, User $user): int
    {
        $ids = $this->appointmentsForEvents($f, $user);

        return AppointmentEvent::where('type', 'rescheduled')->whereBetween('created_at', $f->utcRange())->whereIn('appointment_id', $ids)->count();
    }

    private function appointmentsForEvents(ReportFilters $f, User $user): Builder
    {
        // Events are dated by when they happened; the other filters still apply to their appointment.
        $copy = new ReportFilters(CarbonImmutable::create(2000), CarbonImmutable::create(2100), 'created', 'none', $f->locationIds, $f->specialistIds, $f->serviceIds, [], $f->sources, 'all', $f->creators, $f->locales);

        return $this->appointments($copy, $user, false)->select('appointments.id');
    }

    /** Rows per location (centralizer) or per specialist, from the same filtered set. */
    public function byDimension(ReportFilters $f, User $user, string $dimension): array
    {
        $column = match ($dimension) {
            'location' => 'appointments.location_id',
            'specialist' => 'appointments.specialist_id',
            'service' => 'appointments.service_id',
            'source' => 'appointments.source',
            'locale' => 'appointments.locale',
            default => throw new \InvalidArgumentException($dimension),
        };
        $rows = $this->appointments($f, $user)->selectRaw("$column as k, count(*) as total,
                sum(status = 'cancelled') as cancelled, sum(status = 'no_show') as no_show,
                sum(status in ('arrived','in_consultation','completed','no_show')) as due,
                count(distinct appointments.patient_id) as patients")
            ->groupBy('k')->get();

        return $rows->map(fn ($r) => [
            'key' => $r->k, 'total' => (int) $r->total, 'cancelled' => (int) $r->cancelled, 'no_show' => (int) $r->no_show,
            'cancel_rate' => $r->total ? round($r->cancelled / $r->total * 100, 1) : null,
            'no_show_rate' => $r->due ? round($r->no_show / $r->due * 100, 1) : null,
            'patients' => (int) $r->patients,
        ])->sortByDesc('total')->values()->all();
    }

    /** Location × specialist matrix of appointment counts. */
    public function matrix(ReportFilters $f, User $user): array
    {
        $cells = [];
        foreach ($this->appointments($f, $user)->selectRaw('location_id, specialist_id, count(*) as n')->groupBy('location_id', 'specialist_id')->get() as $r) {
            $cells[$r->location_id][$r->specialist_id] = (int) $r->n;
        }

        return $cells;
    }

    /** Counts per day, week or month (clinic time). */
    public function trend(ReportFilters $f, User $user): array
    {
        $days = $f->from->diffInDays($f->to);
        $unit = $days > 120 ? 'month' : ($days > 31 ? 'week' : 'day');
        $column = $f->basis === 'created' ? 'created_at' : 'starts_at';
        $counts = [];
        foreach ($this->appointments($f, $user)->select($column)->cursor() as $a) {
            $local = CarbonImmutable::instance($a->{$column})->setTimezone(config('optica.clinic_timezone'));
            $key = match ($unit) { 'month' => $local->format('Y-m'), 'week' => $local->startOfWeek()->toDateString(), default => $local->toDateString() };
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        $series = [];
        for ($d = match ($unit) { 'month' => $f->from->startOfMonth(), 'week' => $f->from->startOfWeek(), default => $f->from->startOfDay() }; $d <= $f->to;
            $d = match ($unit) { 'month' => $d->addMonth(), 'week' => $d->addWeek(), default => $d->addDay() }) {
            $key = match ($unit) { 'month' => $d->format('Y-m'), default => $d->toDateString() };
            $series[] = ['key' => $key, 'date' => $d, 'value' => $counts[$key] ?? 0];
        }

        return ['unit' => $unit, 'series' => $series];
    }

    /** Weekday (1–7) × hour distribution of consultations, in clinic time. */
    public function distribution(ReportFilters $f, User $user): array
    {
        $grid = [];
        foreach ($this->appointments($f, $user)->whereNotIn('status', ['cancelled', 'expired'])->select('starts_at')->cursor() as $a) {
            $local = CarbonImmutable::instance($a->starts_at)->setTimezone(config('optica.clinic_timezone'));
            $grid[$local->dayOfWeekIso][(int) $local->format('G')] = ($grid[$local->dayOfWeekIso][(int) $local->format('G')] ?? 0) + 1;
        }

        return $grid;
    }

    /**
     * Occupancy (RAP-03): booked minutes of occupying statuses divided by the working minutes from
     * schedules after breaks, leave and exceptions, times the largest capacity configured.
     *
     * @return list<array{location_id: int, specialist_id: int, available: int, booked: int, rate: ?float}>
     */
    public function occupancy(ReportFilters $f, User $user): array
    {
        $allowed = $this->allowedLocations($user);
        $pairs = DB::table('schedules')->where('is_active', true)
            ->when($allowed !== null, fn ($q) => $q->whereIn('location_id', $allowed))
            ->when($f->locationIds, fn ($q) => $q->whereIn('location_id', $f->locationIds))
            ->when($f->specialistIds, fn ($q) => $q->whereIn('specialist_id', $f->specialistIds))
            ->distinct()->get(['location_id', 'specialist_id']);
        $rows = [];
        foreach ($pairs as $pair) {
            $available = 0;
            foreach ($this->windows->forSpecialist($pair->specialist_id, $pair->location_id, $f->from, $f->to) as $windows) {
                $available += $this->minutes($windows);
            }
            $capacity = max(1, (int) DB::table('offerings')->where('location_id', $pair->location_id)->where('specialist_id', $pair->specialist_id)->where('is_active', true)->max('capacity'));
            $available *= $capacity;
            $booked = (int) Appointment::where('location_id', $pair->location_id)->where('specialist_id', $pair->specialist_id)
                ->whereIn('status', self::OCCUPYING)->whereBetween('starts_at', $f->utcRange())
                ->when($f->serviceIds, fn ($q) => $q->whereIn('service_id', $f->serviceIds))
                ->sum(DB::raw('timestampdiff(minute, starts_at, ends_at)'));
            if ($available || $booked) {
                $rows[] = ['location_id' => $pair->location_id, 'specialist_id' => $pair->specialist_id, 'available' => $available, 'booked' => $booked,
                    'rate' => $available ? round($booked / $available * 100, 1) : null];
            }
        }

        return $rows;
    }

    /** Minutes covered by intervals, overlaps counted once. */
    private function minutes(array $intervals): int
    {
        usort($intervals, fn ($a, $b) => $a->start <=> $b->start);
        $total = 0;
        $end = null;
        foreach ($intervals as $i) {
            $start = $end && $i->start < $end ? $end : $i->start;
            if ($i->end > $start) {
                $total += (int) round($start->diffInMinutes($i->end, true));
                $end = $i->end;
            }
        }

        return $total;
    }

    /**
     * Money (RAP-03, FIN-02): value of services and delivered orders, money received, advances,
     * refunds and open balances, each shown on its own.
     */
    public function financial(ReportFilters $f, User $user): array
    {
        $allowed = $this->allowedLocations($user, 'reports.financial');
        $scope = fn ($q) => $q->when($allowed !== null, fn ($q) => $q->whereIn('location_id', $allowed))
            ->when($f->locationIds, fn ($q) => $q->whereIn('location_id', $f->locationIds));
        [$from, $to] = $f->utcRange();
        $payments = Payment::query()->tap($scope)->valid()->whereBetween('received_at', [$from, $to]);

        $openOrders = Order::query()->tap($scope)->whereNotIn('status', ['cancelled'])->get(['id', 'total_cents']);
        $paidByOrder = Payment::valid()->where('payable_type', 'order')->whereIn('payable_id', $openOrders->pluck('id'))
            ->selectRaw("payable_id, sum(case when type = 'refund' then -amount_cents else amount_cents end) as paid")->groupBy('payable_id')->pluck('paid', 'payable_id');
        $outstanding = $openOrders->sum(fn ($o) => max(0, $o->total_cents - (int) ($paidByOrder[$o->id] ?? 0)));
        $charges = ServiceCharge::query()->tap($scope)->valid()->get(['id', 'amount_cents']);
        $paidByCharge = Payment::valid()->where('payable_type', 'service_charge')->whereIn('payable_id', $charges->pluck('id'))
            ->selectRaw("payable_id, sum(case when type = 'refund' then -amount_cents else amount_cents end) as paid")->groupBy('payable_id')->pluck('paid', 'payable_id');
        $outstanding += $charges->sum(fn ($c) => max(0, $c->amount_cents - (int) ($paidByCharge[$c->id] ?? 0)));

        return [
            'services_value' => (int) ServiceCharge::query()->tap($scope)->valid()->whereBetween('performed_on', [$f->from->toDateString(), $f->to->toDateString()])->sum('amount_cents'),
            'orders_value' => (int) Order::query()->tap($scope)->where('status', 'delivered')->whereBetween('delivered_at', [$from, $to])->sum('total_cents'),
            'received' => (int) (clone $payments)->where('type', 'payment')->sum('amount_cents'),
            'advances' => (int) (clone $payments)->where('type', 'payment')->where('is_advance', true)->sum('amount_cents'),
            'refunds' => (int) (clone $payments)->where('type', 'refund')->sum('amount_cents'),
            'by_method' => (clone $payments)->selectRaw("method, sum(case when type = 'refund' then -amount_cents else amount_cents end) as net")->groupBy('method')->pluck('net', 'method')->map(fn ($v) => (int) $v)->all(),
            'outstanding_now' => $outstanding,
        ];
    }

    /** Sources and lead conversions; a missing source stays visible as such. */
    public function sources(ReportFilters $f, User $user): array
    {
        $allowed = $this->allowedLocations($user);
        $leads = Lead::query()->whereBetween('created_at', $f->utcRange())
            ->when($allowed !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('location_id')->orWhereIn('location_id', $allowed)))
            ->when($f->locationIds, fn ($q) => $q->whereIn('location_id', $f->locationIds));

        return [
            'appointments' => $this->byDimension($f, $user, 'source'),
            'leads_by_type' => (clone $leads)->selectRaw('type, count(*) as total, sum(status = \'converted\') as converted')->groupBy('type')->get()
                ->map(fn ($r) => ['type' => $r->type, 'total' => (int) $r->total, 'converted' => (int) $r->converted])->all(),
            'leads_without_utm' => (clone $leads)->whereNull('utm')->count(),
            'leads_total' => (clone $leads)->count(),
        ];
    }
}
