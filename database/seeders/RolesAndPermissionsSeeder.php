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
        $new = [];
        foreach (PermissionCatalog::all() as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['module' => PermissionCatalog::module($key)]);
            if ($permission->wasRecentlyCreated) {
                $new[] = $key;
            }
        }

        $ids = Permission::pluck('id', 'key');
        foreach (PermissionCatalog::defaultRoles() as $key => $definition) {
            $role = Role::firstOrNew(['key' => $key]);
            if (! $role->exists) {
                $role->fill(['name' => $definition['name'], 'is_system' => true])->save();
                $role->permissions()->sync($ids->only($definition['permissions'])->values());
            } elseif ($added = array_intersect($new, $definition['permissions'])) {
                // A permission introduced by an update reaches the default roles; earlier customisations stay.
                $role->permissions()->syncWithoutDetaching($ids->only($added)->values());
            }
        }

        app(AccessControl::class)->flush();
    }
}
