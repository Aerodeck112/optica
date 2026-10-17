<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\GuardsCatalog;
use App\Models\Service;
use Livewire\Attributes\Url;
use Livewire\Component;

class ServiceIndex extends Component
{
    use GuardsCatalog;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public bool $showDeleted = false;

    public function restore(int $id): void
    {
        $service = Service::onlyTrashed()->findOrFail($id);
        $service->restore();
        $this->audit('catalog.service_restored', $service);
    }

    public function render()
    {
        $term = mb_strtolower($this->search);
        $services = Service::query()
            ->when($this->showDeleted, fn ($q) => $q->onlyTrashed())
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            // Bilingual JSON names: filtered in PHP, the list is small.
            ->filter(fn (Service $s) => $term === '' || str_contains(mb_strtolower($s->name('ro').' '.$s->name('hu').' '.$s->code), $term))
            ->values();

        return view('livewire.admin.service-index', ['services' => $services])
            ->layout('components.layouts.crm')->title(__('crm.services.title'));
    }
}
