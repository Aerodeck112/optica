<?php

namespace App\Livewire\Admin;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Access\AccessControl;
use App\Support\Access\PermissionCatalog;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Customisable roles (ACC-01). Permission changes are audited with the exact difference (ACC-02).
 */
class RoleEditor extends Component
{
    public ?Role $role = null;

    public array $name = ['ro' => '', 'hu' => ''];

    public string $key = '';

    /** @var list<string> */
    public array $permissions = [];

    public function boot(): void
    {
        $this->authorize('roles.manage');
    }

    public function mount(?Role $role = null): void
    {
        $this->role = $role?->exists ? $role->fresh('permissions') : null;
        if ($this->role) {
            $this->name = array_merge(['ro' => '', 'hu' => ''], $this->role->name ?? []);
            $this->key = $this->role->key;
            $this->permissions = $this->role->permissions->pluck('key')->all();
        }
    }

    protected function rules(): array
    {
        return [
            'name.ro' => ['required', 'string', 'max:100'],
            'name.hu' => ['nullable', 'string', 'max:100'],
            'key' => $this->role ? [] : ['nullable', 'string', 'max:60', 'alpha_dash', Rule::unique('roles', 'key')],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(PermissionCatalog::all())],
        ];
    }

    public function save(): void
    {
        $data = $this->validate();
        $new = array_values(array_unique($data['permissions']));

        if ($this->role?->key === 'superadmin' && array_diff(['users.manage', 'roles.manage'], $new)) {
            $this->addError('permissions', __('users.errors.superadmin_core'));

            return;
        }

        $old = $this->role ? $this->role->permissions()->pluck('key')->all() : [];
        $role = DB::transaction(function () use ($data, $new) {
            $role = $this->role ?? new Role([
                'key' => $data['key'] ?: Str::limit(Str::slug($data['name']['ro'], '_'), 50, '').'_'.Str::lower(Str::random(4)),
                'is_system' => false,
            ]);
            $role->name = ['ro' => $data['name']['ro'], 'hu' => $data['name']['hu'] ?: $data['name']['ro']];
            $role->save();
            $role->permissions()->sync(Permission::whereIn('key', $new)->pluck('id'));

            return $role;
        });

        app(AccessControl::class)->flush();
        $added = array_values(array_diff($new, $old));
        $removed = array_values(array_diff($old, $new));
        app(AuditLogger::class)->log($this->role ? 'roles.updated' : 'roles.created', $role, array_filter([
            'added' => $added, 'removed' => $removed,
            'clinical_added' => array_values(array_intersect($added, PermissionCatalog::CLINICAL)),
        ]));

        session()->flash('status', __('users.roles.saved'));
        $this->redirectRoute('admin.roles.edit', $role, navigate: true);
    }

    public function delete(): void
    {
        abort_unless($this->role, 404);
        if ($this->role->is_system || $this->role->users()->exists()) {
            $this->addError('delete', __('users.roles.delete_blocked'));

            return;
        }
        app(AuditLogger::class)->log('roles.deleted', $this->role, ['key' => $this->role->key]);
        $this->role->delete();
        session()->flash('status', __('crm.deleted'));
        $this->redirectRoute('admin.roles.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.role-editor', [
            'catalog' => PermissionCatalog::PERMISSIONS,
            'clinical' => PermissionCatalog::CLINICAL,
        ])->layout('components.layouts.crm')->title($this->role?->label() ?? __('users.roles.create'));
    }
}
