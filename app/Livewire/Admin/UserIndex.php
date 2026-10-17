<?php

namespace App\Livewire\Admin;

use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class UserIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $role = '';

    #[Url]
    public ?int $location = null;

    #[Url]
    public string $state = 'active';

    public function boot(): void
    {
        $this->authorize('users.view');
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $users = User::query()
            ->withTrashed()
            ->with(['roles', 'locations', 'specialist'])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('email', 'like', '%'.$this->search.'%')))
            ->when($this->role !== '', fn ($q) => $q->whereHas('roles', fn ($q) => $q->where('key', $this->role)))
            ->when($this->location, fn ($q) => $q->where(fn ($q) => $q
                ->where('all_locations', true)
                ->orWhereHas('locations', fn ($q) => $q->whereKey($this->location))))
            ->when($this->state === 'active', fn ($q) => $q->whereNull('deleted_at')->where('is_active', true))
            ->when($this->state === 'inactive', fn ($q) => $q->where(fn ($q) => $q->whereNotNull('deleted_at')->orWhere('is_active', false)))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.admin.user-index', [
            'users' => $users,
            'roles' => Role::orderBy('id')->get(),
            'locations' => Location::orderBy('sort_order')->get(),
        ])->layout('components.layouts.crm')->title(__('users.title'));
    }
}
