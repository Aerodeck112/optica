<?php

namespace App\Livewire\Crm;

use App\Domain\Reports\ReportEngine;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportTables;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Jobs\ExportReport;
use App\Models\Location;
use App\Models\ReportExport;
use App\Models\ReportSubscription;
use App\Models\SavedReport;
use App\Models\Service;
use App\Models\Specialist;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Reports (RAP-01…05): one set of filters drives every view; each indicator states its rule; every
 * figure can be opened down to the appointments behind it. Managers see only their locations.
 */
class Reports extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'summary';

    #[Url(as: 'f')]
    public array $filters = [];

    public bool $moreFilters = false;

    public ?int $savedId = null;

    public string $saveName = '';

    public array $subscription = ['name' => '', 'report' => 'summary', 'frequency' => 'weekly', 'format' => 'xlsx', 'locale' => 'ro', 'recipients' => ''];

    public bool $showSubscriptions = false;

    public ?string $notice = null;

    public function boot(): void
    {
        $this->authorize('reports.view');
    }

    public function mount(): void
    {
        $this->filters = ReportFilters::fromArray($this->filters)->toArray();
        $this->subscription['locale'] = app()->getLocale();
        if (! in_array($this->tab, $this->tabs(), true)) {
            $this->tab = 'summary';
        }
    }

    public function tabs(): array
    {
        $tabs = ['summary', 'locations', 'specialists', 'services', 'matrix', 'occupancy', 'trend', 'distribution', 'sources', 'records'];
        if (auth()->user()->can('reports.financial')) {
            array_splice($tabs, 8, 0, ['financial']);
        }

        return $tabs;
    }

    public function updatedTab(): void
    {
        if (! in_array($this->tab, $this->tabs(), true)) {
            $this->tab = 'summary';
        }
        $this->resetPage();
    }

    public function updatedFilters(): void
    {
        $this->filters = ReportFilters::fromArray($this->filters)->toArray();
        $this->resetPage();
    }

    public function preset(string $preset): void
    {
        $tz = config('optica.clinic_timezone');
        $now = CarbonImmutable::now($tz);
        [$from, $to] = match ($preset) {
            'today' => [$now, $now],
            '7d' => [$now->subDays(6), $now],
            'month' => [$now->startOfMonth(), $now],
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'year' => [$now->startOfYear(), $now],
            default => abort(422),
        };
        $this->filters['from'] = $from->toDateString();
        $this->filters['to'] = $to->toDateString();
        $this->updatedFilters();
    }

    /** Drill-down: a row of a breakdown becomes a filter and opens the next level (RAP-01). */
    public function drill(string $dimension, int|string $value): void
    {
        match ($dimension) {
            'location' => [$this->filters['locations'], $this->tab] = [[(int) $value], 'specialists'],
            'specialist' => [$this->filters['specialists'], $this->tab] = [[(int) $value], 'records'],
            'service' => [$this->filters['services'], $this->tab] = [[(int) $value], 'records'],
            'source' => [$this->filters['sources'], $this->tab] = [[(string) $value], 'records'],
            default => abort(422),
        };
        $this->updatedFilters();
    }

    public function clearFilter(string $key): void
    {
        abort_unless(in_array($key, ['locations', 'specialists', 'services', 'statuses', 'sources', 'creators', 'locales', 'patient_type'], true), 422);
        $this->filters[$key] = $key === 'patient_type' ? 'all' : [];
        $this->updatedFilters();
    }

    public function resetFilters(): void
    {
        $this->filters = ReportFilters::fromArray(['from' => $this->filters['from'] ?? null, 'to' => $this->filters['to'] ?? null])->toArray();
        $this->savedId = null;
    }

    // ----- saved filters ----------------------------------------------------------------------

    public function saveFilters(): void
    {
        $this->validate(['saveName' => ['required', 'string', 'max:120']], [], ['saveName' => __('reports.saved_name')]);
        $saved = SavedReport::create(['user_id' => auth()->id(), 'name' => trim($this->saveName), 'filters' => $this->filters]);
        $this->savedId = $saved->id;
        $this->saveName = '';
        $this->notice = __('reports.saved');
    }

    public function updatedSavedId(): void
    {
        if ($this->savedId) {
            $this->filters = ReportFilters::fromArray(SavedReport::where('user_id', auth()->id())->findOrFail($this->savedId)->filters)->toArray();
        }
    }

    public function deleteSaved(): void
    {
        SavedReport::where('user_id', auth()->id())->whereKey($this->savedId)->delete();
        $this->savedId = null;
    }

    // ----- exports ----------------------------------------------------------------------------

    public function export(string $format): void
    {
        $this->authorize('reports.export');
        abort_unless(in_array($format, ReportExport::FORMATS, true), 422);
        $report = in_array($this->tab, ReportTables::REPORTS, true) ? $this->tab : 'summary';
        if (in_array($report, ReportTables::FINANCIAL, true)) {
            $this->authorize('reports.financial');
        }
        $export = ReportExport::create(['uuid' => (string) Str::uuid(), 'user_id' => auth()->id(), 'report' => $report, 'format' => $format,
            'locale' => app()->getLocale(), 'filters' => $this->filters]);
        ExportReport::dispatch($export->id);
        $this->notice = __('reports.export_queued');
    }

    // ----- scheduled reports ------------------------------------------------------------------

    public function subscribe(): void
    {
        $this->authorize('reports.export');
        $recipients = array_values(array_unique(array_filter(array_map(fn ($r) => mb_strtolower(trim($r)), preg_split('/[,;\s]+/', $this->subscription['recipients'])))));
        $this->validate([
            'subscription.name' => ['required', 'string', 'max:120'],
            'subscription.report' => ['required', Rule::in(ReportTables::REPORTS)],
            'subscription.frequency' => ['required', Rule::in(ReportSubscription::FREQUENCIES)],
            'subscription.format' => ['required', Rule::in(ReportExport::FORMATS)],
            'subscription.locale' => ['required', Rule::in(config('optica.locales'))],
            'subscription.recipients' => ['required', 'string', 'max:1000'],
        ], [], ['subscription.name' => __('reports.subscriptions.name'), 'subscription.recipients' => __('reports.subscriptions.recipients')]);
        foreach ($recipients as $r) {
            if (! filter_var($r, FILTER_VALIDATE_EMAIL)) {
                $this->addError('subscription.recipients', __('validation.email', ['attribute' => $r]));

                return;
            }
        }
        if (in_array($this->subscription['report'], ReportTables::FINANCIAL, true)) {
            $this->authorize('reports.financial');
        }
        // The period comes from the frequency; the other filters are kept.
        $filters = $this->filters;
        unset($filters['from'], $filters['to']);
        ReportSubscription::create(['name' => $this->subscription['name'], 'report' => $this->subscription['report'], 'frequency' => $this->subscription['frequency'],
            'format' => $this->subscription['format'], 'locale' => $this->subscription['locale'], 'recipients' => $recipients, 'filters' => $filters, 'created_by' => auth()->id()]);
        $this->subscription['name'] = $this->subscription['recipients'] = '';
        $this->notice = __('reports.subscriptions.saved');
    }

    public function toggleSubscription(int $id): void
    {
        $this->authorize('reports.export');
        $s = ReportSubscription::where('created_by', auth()->id())->findOrFail($id);
        $s->update(['is_active' => ! $s->is_active]);
    }

    public function deleteSubscription(int $id): void
    {
        $this->authorize('reports.export');
        ReportSubscription::where('created_by', auth()->id())->whereKey($id)->delete();
    }

    public function render(ReportEngine $engine)
    {
        $user = auth()->user();
        $f = ReportFilters::fromArray($this->filters);
        $data = ['f' => $f, 'tz' => config('optica.clinic_timezone')];
        $names = fn (string $model, array $ids) => $model::whereIn('id', array_filter($ids))->get()->mapWithKeys(fn ($m) => [$m->id => $m instanceof Specialist ? $m->display_name : $m->name()])->all();

        switch ($this->tab) {
            case 'summary':
                $data['summary'] = $engine->summary($f, $user);
                $data['previous'] = ($cf = $f->comparison()) ? $engine->summary($cf, $user) : null;
                $data['comparisonRange'] = $cf ?? null;
                break;
            case 'locations':
            case 'specialists':
            case 'services':
                $dimension = rtrim($this->tab, 's');
                $data['rows'] = $engine->byDimension($f, $user, $dimension);
                $data['names'] = $names(['location' => Location::class, 'specialist' => Specialist::class, 'service' => Service::class][$dimension], array_column($data['rows'], 'key'));
                $data['dimension'] = $dimension;
                break;
            case 'matrix':
                $data['cells'] = $engine->matrix($f, $user);
                $data['locationNames'] = $names(Location::class, array_keys($data['cells']));
                $data['specialistNames'] = $names(Specialist::class, collect($data['cells'])->flatMap(fn ($r) => array_keys($r))->unique()->all());
                break;
            case 'occupancy':
                $data['rows'] = $engine->occupancy($f, $user);
                $data['locationNames'] = $names(Location::class, array_column($data['rows'], 'location_id'));
                $data['specialistNames'] = $names(Specialist::class, array_column($data['rows'], 'specialist_id'));
                break;
            case 'trend':
                $data['trend'] = $engine->trend($f, $user);
                break;
            case 'distribution':
                $data['grid'] = $engine->distribution($f, $user);
                break;
            case 'financial':
                $this->authorize('reports.financial');
                $data['money'] = $engine->financial($f, $user);
                break;
            case 'sources':
                $data['sources'] = $engine->sources($f, $user);
                break;
            case 'records':
                $data['records'] = $engine->appointments($f, $user)->with(['patient', 'location', 'specialist', 'service'])->orderBy('starts_at')->paginate(50);
                $data['withNames'] = $user->can('patients.view');
                break;
        }

        $allowed = $engine->allowedLocations($user);

        return view('livewire.crm.reports', $data + [
            'locations' => Location::when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->orderBy('sort_order')->get(),
            'specialists' => Specialist::orderBy('display_name')->get(['id', 'display_name']),
            'services' => Service::orderBy('sort_order')->get(),
            'creators' => User::whereIn('id', \App\Models\Appointment::query()->whereNotNull('created_by')->distinct()->pluck('created_by'))->orderBy('name')->get(['id', 'name']),
            'statuses' => array_values(array_diff(array_map(fn ($s) => $s->value, AppointmentStatus::cases()), ReportEngine::EXCLUDED)),
            'sourcesList' => array_map(fn ($s) => $s->value, AppointmentSource::cases()),
            'savedReports' => SavedReport::where('user_id', $user->id)->orderBy('name')->get(),
            'exports' => ReportExport::where('user_id', $user->id)->latest('id')->limit(8)->get(),
            'subscriptions' => $this->showSubscriptions ? ReportSubscription::where('created_by', $user->id)->latest('id')->get() : collect(),
            'tabs' => $this->tabs(),
        ])->layout('components.layouts.crm')->title(__('reports.title'));
    }
}
