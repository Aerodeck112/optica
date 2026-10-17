<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\GuardsCatalog;
use App\Models\Location;
use App\Models\LocationSpecialist;
use App\Models\Offering;
use App\Models\Service;
use App\Models\Specialist;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * What a specialist offers in each location, and how it is booked (LOC-03): duration, buffers,
 * parallel capacity, required equipment, price and online availability. Plus the per-location
 * online settings seen in Evolvo: minimum notice, booking horizon, pause and its message.
 */
class OfferingEditor extends Component
{
    use GuardsCatalog;

    public Specialist $specialist;

    /** @var array<int, array<int, array<string, mixed>>> location id => service id => row */
    public array $rows = [];

    /** @var array<int, array<string, mixed>> location id => online settings */
    public array $online = [];

    public function mount(Specialist $specialist): void
    {
        $this->specialist = $specialist;
        $offerings = Offering::where('specialist_id', $specialist->id)->get()->groupBy('location_id');

        foreach ($this->links() as $link) {
            $byService = ($offerings[$link->location_id] ?? collect())->keyBy('service_id');
            foreach (Service::where('is_active', true)->orderBy('sort_order')->get() as $service) {
                $o = $byService->get($service->id);
                $this->rows[$link->location_id][$service->id] = [
                    'offered' => (bool) ($o?->is_active),
                    'duration' => $o?->duration_minutes,
                    'capacity' => $o?->capacity ?? 1,
                    'buffer_before' => $o?->buffer_before,
                    'buffer_after' => $o?->buffer_after,
                    'price' => $o?->price !== null ? (string) $o->price : null,
                    'resource_type' => $o?->resource_type,
                    'online' => (bool) ($o?->online_enabled),
                ];
            }
            $this->online[$link->location_id] = [
                'min_lead_hours' => intdiv((int) $link->min_lead_minutes, 60),
                'max_advance_days' => (int) $link->max_advance_days,
                'paused' => (bool) $link->online_paused,
                'pause_ro' => (string) ($link->content['ro']['pause_message'] ?? ''),
                'pause_hu' => (string) ($link->content['hu']['pause_message'] ?? ''),
                'notify' => (bool) $link->notify_on_online_booking,
            ];
        }
    }

    protected function rules(): array
    {
        return [
            'rows.*.*.offered' => ['boolean'],
            'rows.*.*.duration' => ['nullable', 'integer', 'min:5', 'max:480'],
            'rows.*.*.capacity' => ['required', 'integer', 'min:1', 'max:20'],
            'rows.*.*.buffer_before' => ['nullable', 'integer', 'min:0', 'max:120'],
            'rows.*.*.buffer_after' => ['nullable', 'integer', 'min:0', 'max:120'],
            'rows.*.*.price' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'rows.*.*.resource_type' => ['nullable', Rule::in(ResourceManager::TYPES)],
            'rows.*.*.online' => ['boolean'],
            'online.*.min_lead_hours' => ['required', 'integer', 'min:0', 'max:720'],
            'online.*.max_advance_days' => ['required', 'integer', 'min:1', 'max:365'],
            'online.*.paused' => ['boolean'],
            'online.*.pause_ro' => ['nullable', 'string', 'max:500'],
            'online.*.pause_hu' => ['nullable', 'string', 'max:500'],
            'online.*.notify' => ['boolean'],
        ];
    }

    public function save(): void
    {
        $this->validate();
        $linkedLocations = $this->links()->pluck('location_id')->map(fn ($id) => (int) $id)->all();
        $serviceIds = Service::pluck('id')->map(fn ($id) => (int) $id)->all();

        DB::transaction(function () use ($linkedLocations, $serviceIds) {
            foreach ($this->rows as $locationId => $services) {
                if (! in_array((int) $locationId, $linkedLocations, true)) {
                    continue;
                }
                foreach ($services as $serviceId => $row) {
                    if (! in_array((int) $serviceId, $serviceIds, true)) {
                        continue;
                    }
                    $offering = Offering::firstOrNew([
                        'location_id' => (int) $locationId,
                        'specialist_id' => $this->specialist->id,
                        'service_id' => (int) $serviceId,
                    ]);
                    if (! $offering->exists && ! $row['offered']) {
                        continue;
                    }
                    // Withdrawn offerings stay as inactive rows: past appointments keep their context (ARC-07).
                    $offering->fill([
                        'is_active' => (bool) $row['offered'],
                        'duration_minutes' => $this->intOrNull($row['duration']),
                        'capacity' => max(1, (int) $row['capacity']),
                        'buffer_before' => $this->intOrNull($row['buffer_before']),
                        'buffer_after' => $this->intOrNull($row['buffer_after']),
                        'price' => $row['price'] === null || $row['price'] === '' ? null : $row['price'],
                        'resource_type' => $row['resource_type'] ?: null,
                        'online_enabled' => $row['offered'] && $row['online'],
                    ])->save();
                }
            }

            foreach ($this->online as $locationId => $settings) {
                $link = LocationSpecialist::where(['location_id' => (int) $locationId, 'specialist_id' => $this->specialist->id])->first();
                if (! $link) {
                    continue;
                }
                $content = $link->content ?? [];
                $content['ro']['pause_message'] = $settings['pause_ro'];
                $content['hu']['pause_message'] = $settings['pause_hu'];
                $link->fill([
                    'min_lead_minutes' => (int) $settings['min_lead_hours'] * 60,
                    'max_advance_days' => (int) $settings['max_advance_days'],
                    'online_paused' => (bool) $settings['paused'],
                    'notify_on_online_booking' => (bool) $settings['notify'],
                    'content' => $content,
                ])->save();
            }
        });

        $this->audit('catalog.offerings_updated', $this->specialist);
        session()->flash('status', __('catalog.offerings.saved'));
        $this->redirectRoute('admin.specialists.services', $this->specialist, navigate: true);
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function links()
    {
        return LocationSpecialist::where('specialist_id', $this->specialist->id)->where('is_active', true)->get();
    }

    public function render()
    {
        $locations = Location::whereIn('id', array_keys($this->rows))->orderBy('sort_order')->get();
        $resourceTypes = [];
        foreach ($locations as $l) {
            $resourceTypes[$l->id] = $l->resources()->where('is_active', true)->distinct()->pluck('type')->all();
        }

        return view('livewire.admin.offering-editor', [
            'locations' => $locations,
            'services' => Service::where('is_active', true)->orderBy('sort_order')->get()->keyBy('id'),
            'resourceTypes' => $resourceTypes,
        ])->layout('components.layouts.crm')->title(__('catalog.offerings.title', ['name' => $this->specialist->display_name]));
    }
}
