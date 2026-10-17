<?php

namespace App\Livewire\Admin;

use App\Enums\AppointmentStatus;
use App\Livewire\Concerns\GuardsCatalog;
use App\Models\Appointment;
use App\Models\Location;
use App\Models\Resource;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Rooms and equipment of a location (LOC-03). The type links resources to the services that need them.
 */
class ResourceManager extends Component
{
    use GuardsCatalog;

    public const TYPES = ['cabinet', 'oct', 'visual_field', 'ultrasound', 'synoptophore', 'topography', 'other'];

    public Location $location;

    public ?int $editingId = null;

    public string $type = 'cabinet';

    public array $name = ['ro' => '', 'hu' => ''];

    public bool $is_active = true;

    public function edit(int $id): void
    {
        $resource = $this->location->resources()->findOrFail($id);
        $this->editingId = $resource->id;
        $this->type = $resource->type;
        $this->name = array_merge(['ro' => '', 'hu' => ''], $resource->name ?? []);
        $this->is_active = (bool) $resource->is_active;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'type', 'name', 'is_active');
        $this->resetValidation();
    }

    public function save(): void
    {
        $data = $this->validate([
            'type' => ['required', Rule::in(self::TYPES)],
            'name.ro' => ['required', 'string', 'max:120'],
            'name.hu' => ['nullable', 'string', 'max:120'],
            'is_active' => ['boolean'],
        ]);

        $resource = $this->editingId ? $this->location->resources()->findOrFail($this->editingId) : new Resource(['location_id' => $this->location->id]);
        $resource->fill([
            'type' => $data['type'],
            'name' => ['ro' => $data['name']['ro'], 'hu' => $data['name']['hu'] ?: $data['name']['ro']],
            'is_active' => $data['is_active'],
        ])->save();
        $this->audit($this->editingId ? 'catalog.resource_updated' : 'catalog.resource_created', $resource);
        $this->cancel();
    }

    public function delete(int $id): void
    {
        $resource = $this->location->resources()->findOrFail($id);
        $future = Appointment::where('resource_id', $resource->id)
            ->where('starts_at', '>=', now())
            ->whereIn('status', AppointmentStatus::blockingValues())
            ->count();
        if ($future > 0) {
            $this->addError('delete', trans_choice('crm.delete_blocked_appointments', $future, ['count' => $future]));

            return;
        }
        $this->audit('catalog.resource_deleted', $resource);
        $resource->delete();
    }

    public function render()
    {
        return view('livewire.admin.resource-manager', [
            'resources' => $this->location->resources()->orderBy('type')->orderBy('id')->get(),
        ]);
    }
}
