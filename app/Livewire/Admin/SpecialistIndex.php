<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\GuardsCatalog;
use App\Models\Location;
use App\Models\Specialist;
use Livewire\Attributes\Url;
use Livewire\Component;

class SpecialistIndex extends Component
{
    use GuardsCatalog;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $type = '';

    #[Url]
    public ?int $location = null;

    #[Url]
    public bool $showDeleted = false;

    public function restore(int $id): void
    {
        $specialist = Specialist::onlyTrashed()->findOrFail($id);
        $specialist->restore();
        $this->audit('catalog.specialist_restored', $specialist);
    }

    public function render()
    {
        $specialists = Specialist::query()
            ->with('locations')
            ->when($this->showDeleted, fn ($q) => $q->onlyTrashed())
            ->when($this->search !== '', fn ($q) => $q->where('display_name', 'like', '%'.$this->search.'%'))
            ->when(in_array($this->type, Specialist::TYPES, true), fn ($q) => $q->where('type', $this->type))
            ->when($this->location, fn ($q) => $q->whereHas('locations', fn ($q) => $q->whereKey($this->location)))
            ->orderBy('sort_order')->orderBy('display_name')
            ->get();

        return view('livewire.admin.specialist-index', [
            'specialists' => $specialists,
            'locations' => Location::orderBy('sort_order')->get(),
        ])->layout('components.layouts.crm')->title(__('crm.specialists.title'));
    }
}
