<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Access\AccessControl;
use App\Support\Access\PermissionCatalog;
use Illuminate\Database\Seeder;

/**
 * Idempotent: safe to run on every install and update. Existing roles keep the customer's
 * changes; only missing permissions/roles are created (UPD-05).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalog::all() as $key) {
            Permission::firstOrCreate(['key' => $key], ['module' => PermissionCatalog::module($key)]);
        }

        $ids = Permission::pluck('id', 'key');
        foreach (PermissionCatalog::defaultRoles() as $key => $definition) {
            $role = Role::firstOrNew(['key' => $key]);
            if (! $role->exists) {
                $role->fill(['name' => $definition['name'], 'is_system' => true])->save();
                $role->permissions()->sync($ids->only($definition['permissions'])->values());
            }
        }

        app(AccessControl::class)->flush();
    }
}
