<?php

namespace App\Livewire\Admin;

use App\Domain\Scheduling\AffectedAppointments;
use App\Models\Location;
use App\Models\Schedule;
use App\Models\Specialist;
use App\Support\Access\AccessControl;
use Livewire\Attributes\Url;
use Livewire\Component;

class ScheduleIndex extends Component
{
    #[Url]
    public ?int $specialist = null;

    #[Url]
    public ?int $location = null;

    public function boot(): void
    {
        $this->authorize('schedules.view');
    }

    public function render(AccessControl $access, AffectedAppointments $affected)
    {
        $allowed = $access->locationIdsFor(auth()->user(), 'schedules.view');
        $schedules = Schedule::query()
            ->with(['specialist', 'location', 'segments'])
            ->when($allowed !== null, fn ($q) => $q->whereIn('location_id', $allowed))
            ->when($this->specialist, fn ($q) => $q->where('specialist_id', $this->specialist))
            ->when($this->location, fn ($q) => $q->where('location_id', $this->location))
            ->orderByDesc('is_active')->orderByDesc('valid_from')
            ->get();

        $affectedList = auth()->user()->can('schedules.manage')
            ? $affected->find($this->specialist, $this->location)
                ->filter(fn ($a) => $allowed === null || in_array($a->location_id, $allowed, true))
            : collect();

        return view('livewire.admin.schedule-index', [
            'schedules' => $schedules,
            'affected' => $affectedList,
            'specialists' => Specialist::orderBy('display_name')->get(),
            'locations' => Location::when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->orderBy('sort_order')->get(),
        ])->layout('components.layouts.crm')->title(__('catalog.schedules.title'));
    }
}
