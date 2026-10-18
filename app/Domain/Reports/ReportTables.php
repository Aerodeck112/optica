<?php

namespace App\Domain\Reports;

use App\Models\Location;
use App\Models\Service;
use App\Models\Specialist;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Each exportable report as a plain table (title, headers, rows) in the requested language, built
 * from the same engine as the screen, so the file and the page always agree (RAP-05).
 */
class ReportTables
{
    public const REPORTS = ['summary', 'locations', 'specialists', 'services', 'matrix', 'occupancy', 'financial', 'sources', 'records'];

    /** Reports with money need the financial right. */
    public const FINANCIAL = ['financial'];

    public const MAX_ROWS = 50000;

    public function __construct(private readonly ReportEngine $engine) {}

    /** @return array{title: string, headers: list<string>, rows: list<list<string|int|float|null>>} */
    public function build(string $report, ReportFilters $f, User $user, string $locale): array
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);
        try {
            return $this->{$report === 'records' ? 'records' : 'table_'.$report}($f, $user);
        } finally {
            app()->setLocale($previous);
        }
    }

    private function names(string $model, array $ids): array
    {
        return $model::whereIn('id', array_filter($ids))->get()->mapWithKeys(fn ($m) => [$m->id => $m instanceof Specialist ? $m->display_name : $m->name()])->all();
    }

    private function table_summary(ReportFilters $f, User $user): array
    {
        $s = $this->engine->summary($f, $user);
        $c = ($cf = $f->comparison()) ? $this->engine->summary($cf, $user) : null;
        $row = fn ($key, $value, $prev = null, $rule = '') => [__('reports.kpi.'.$key), $value, $c ? $prev : null, $rule];
        $rows = [
            $row('appointments', $s['appointments'], $c['appointments'] ?? null, __('reports.rules.appointments')),
            $row('visits', $s['visits'], $c['visits'] ?? null, __('reports.rules.visits')),
            $row('unique_patients', $s['unique_patients'], $c['unique_patients'] ?? null, __('reports.rules.unique_patients')),
            $row('cancel_rate', $s['cancel_rate'], $c['cancel_rate'] ?? null, __('reports.rules.cancel_rate', ['n' => $s['cancelled'], 'd' => $s['appointments']])),
            $row('no_show_rate', $s['no_show_rate'], $c['no_show_rate'] ?? null, __('reports.rules.no_show_rate', ['n' => $s['no_show'], 'd' => $s['no_show_due']])),
            $row('reschedules', $s['reschedules'], $c['reschedules'] ?? null, __('reports.rules.reschedules')),
            $row('avg_wait_days', $s['avg_wait_days'], $c['avg_wait_days'] ?? null, __('reports.rules.avg_wait')),
            $row('avg_visit_minutes', $s['avg_visit_minutes'], $c['avg_visit_minutes'] ?? null, __('reports.rules.avg_visit', ['n' => $s['visits_with_duration']])),
        ];
        foreach ($s['by_status'] as $status => $n) {
            $rows[] = [__('reports.kpi.status', ['status' => __('appointments.status.'.$status)]), $n, $c['by_status'][$status] ?? null, ''];
        }

        return ['title' => __('reports.tabs.summary'), 'headers' => [__('reports.indicator'), __('reports.value'), __('reports.comparison'), __('reports.rule')], 'rows' => $rows];
    }

    private function dimension(ReportFilters $f, User $user, string $dimension, string $model, string $title): array
    {
        $data = $this->engine->byDimension($f, $user, $dimension);
        $names = $this->names($model, array_column($data, 'key'));

        return ['title' => $title, 'headers' => [__('reports.name'), __('reports.kpi.appointments'), __('reports.kpi.cancelled'), __('reports.kpi.cancel_rate'), __('reports.kpi.no_show'), __('reports.kpi.no_show_rate'), __('reports.kpi.patients_in_row')],
            'rows' => array_map(fn ($r) => [$names[$r['key']] ?? '—', $r['total'], $r['cancelled'], $r['cancel_rate'], $r['no_show'], $r['no_show_rate'], $r['patients']], $data)];
    }

    private function table_locations(ReportFilters $f, User $user): array
    {
        return $this->dimension($f, $user, 'location', Location::class, __('reports.tabs.locations'));
    }

    private function table_specialists(ReportFilters $f, User $user): array
    {
        return $this->dimension($f, $user, 'specialist', Specialist::class, __('reports.tabs.specialists'));
    }

    private function table_services(ReportFilters $f, User $user): array
    {
        return $this->dimension($f, $user, 'service', Service::class, __('reports.tabs.services'));
    }

    private function table_matrix(ReportFilters $f, User $user): array
    {
        $cells = $this->engine->matrix($f, $user);
        $locations = $this->names(Location::class, array_keys($cells));
        $specialistIds = collect($cells)->flatMap(fn ($row) => array_keys($row))->unique()->all();
        $specialists = $this->names(Specialist::class, $specialistIds);
        $rows = [];
        foreach ($cells as $locationId => $row) {
            $rows[] = array_merge([$locations[$locationId] ?? '—'], array_map(fn ($id) => $row[$id] ?? 0, array_keys($specialists)), [array_sum($row)]);
        }

        return ['title' => __('reports.tabs.matrix'), 'headers' => array_merge([__('crm.fields.location')], array_values($specialists), [__('reports.total')]), 'rows' => $rows];
    }

    private function table_occupancy(ReportFilters $f, User $user): array
    {
        $data = $this->engine->occupancy($f, $user);
        $locations = $this->names(Location::class, array_column($data, 'location_id'));
        $specialists = $this->names(Specialist::class, array_column($data, 'specialist_id'));

        return ['title' => __('reports.tabs.occupancy'), 'headers' => [__('crm.fields.location'), __('crm.fields.specialist'), __('reports.available_minutes'), __('reports.booked_minutes'), __('reports.kpi.occupancy')],
            'rows' => array_map(fn ($r) => [$locations[$r['location_id']] ?? '—', $specialists[$r['specialist_id']] ?? '—', $r['available'], $r['booked'], $r['rate']], $data)];
    }

    private function table_financial(ReportFilters $f, User $user): array
    {
        $m = $this->engine->financial($f, $user);
        $rows = [];
        foreach (['services_value', 'orders_value', 'received', 'advances', 'refunds', 'outstanding_now'] as $key) {
            $rows[] = [__('reports.money.'.$key), Money::format($m[$key], false)];
        }
        foreach ($m['by_method'] as $method => $cents) {
            $rows[] = [__('reports.money.method', ['method' => __('finance.methods.'.$method)]), Money::format($cents, false)];
        }

        return ['title' => __('reports.tabs.financial'), 'headers' => [__('reports.indicator'), __('reports.value_lei')], 'rows' => $rows];
    }

    private function table_sources(ReportFilters $f, User $user): array
    {
        $s = $this->engine->sources($f, $user);
        $rows = array_map(fn ($r) => [__('reports.source_appointments', ['source' => __('appointments.source.'.$r['key'])]), $r['total']], $s['appointments']);
        foreach ($s['leads_by_type'] as $r) {
            $rows[] = [__('reports.source_leads', ['type' => __('leads.types.'.$r['type'])]), $r['total'].' / '.$r['converted']];
        }
        $rows[] = [__('reports.leads_without_utm'), $s['leads_without_utm'].' / '.$s['leads_total']];

        return ['title' => __('reports.tabs.sources'), 'headers' => [__('reports.indicator'), __('reports.value')], 'rows' => $rows];
    }

    /** The appointments behind the indicators (drill-down). Names only for people who may see patients. */
    private function records(ReportFilters $f, User $user): array
    {
        $tz = config('optica.clinic_timezone');
        $withNames = $user->can('patients.view');
        $rows = [];
        foreach ($this->engine->appointments($f, $user)->with(['patient', 'location', 'specialist', 'service'])->orderBy('starts_at')->limit(self::MAX_ROWS)->cursor() as $a) {
            $rows[] = [
                CarbonImmutable::instance($a->starts_at)->setTimezone($tz)->format('d.m.Y H:i'),
                $a->location?->name(), $a->specialist?->display_name, $a->service?->name(),
                __('appointments.status.'.$a->status->value), __('appointments.source.'.$a->source->value),
                $withNames ? ($a->patient?->fullName() ?? $a->contact_name) : ($a->patient?->code ?? '—'),
                CarbonImmutable::instance($a->created_at)->setTimezone($tz)->format('d.m.Y H:i'),
            ];
        }

        return ['title' => __('reports.tabs.records'), 'headers' => [__('crm.fields.time'), __('crm.fields.location'), __('crm.fields.specialist'), __('crm.fields.service'),
            __('crm.fields.status'), __('reports.source'), __('crm.fields.patient'), __('reports.created_at')], 'rows' => $rows];
    }
}
