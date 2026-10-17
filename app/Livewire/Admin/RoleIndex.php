<?php

namespace App\Livewire\Admin;

use App\Models\Role;
use Livewire\Component;

class RoleIndex extends Component
{
    public function boot(): void
    {
        $this->authorize('roles.manage');
    }

    public function render()
    {
        return view('livewire.admin.role-index', [
            'roles' => Role::withCount(['users', 'permissions'])->orderBy('id')->get(),
        ])->layout('components.layouts.crm')->title(__('users.roles.title'));
    }
}
