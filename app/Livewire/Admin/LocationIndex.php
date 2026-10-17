<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\GuardsCatalog;
use App\Models\Location;
use Livewire\Attributes\Url;
use Livewire\Component;

class LocationIndex extends Component
{
    use GuardsCatalog;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public bool $showDeleted = false;

    public function restore(int $id): void
    {
        $location = Location::onlyTrashed()->findOrFail($id);
        $location->restore();
        $this->audit('catalog.location_restored', $location);
    }

    public function render()
    {
        $locations = Location::query()
            ->when($this->showDeleted, fn ($q) => $q->onlyTrashed())
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('city', 'like', '%'.$this->search.'%')
                ->orWhere('address', 'like', '%'.$this->search.'%')
                ->orWhere('code', 'like', '%'.$this->search.'%')))
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        return view('livewire.admin.location-index', ['locations' => $locations])
            ->layout('components.layouts.crm')
            ->title(__('crm.locations.title'));
    }
}
