<?php

namespace Database\Seeders;

use App\Modules\Core\Access\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(Permissions::ALL) as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach (array_keys(Permissions::ROLES) as $role) {
            Role::findOrCreate($role, 'web')->syncPermissions(Permissions::forRole($role));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
