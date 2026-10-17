<?php

namespace Tests\Feature\Access;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\AccessControl;
use App\Support\Access\PermissionCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase, SchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $roleKey, array $locationIds = [], bool $all = false): User
    {
        $user = User::factory()->create(['all_locations' => $all]);
        $user->roles()->attach(Role::where('key', $roleKey)->value('id'));
        $user->locations()->attach($locationIds);
        app(AccessControl::class)->flush();

        return $user;
    }

    public function test_users_without_roles_are_denied_everything(): void
    {
        $user = User::factory()->create(['all_locations' => true]);

        foreach (PermissionCatalog::all() as $permission) {
            $this->assertFalse($user->hasPermission($permission), $permission);
            $this->assertFalse(Gate::forUser($user)->allows($permission), $permission);
        }
    }

    public function test_permissions_are_limited_to_assigned_locations(): void
    {
        $a = $this->makeLocation();
        $b = $this->makeLocation();
        $reception = $this->userWithRole('reception', [$a->id]);

        $this->assertTrue($reception->hasPermission('appointments.create', $a));
        $this->assertFalse($reception->hasPermission('appointments.create', $b));
        $this->assertTrue(Gate::forUser($reception)->allows('appointments.create', $a));
        $this->assertFalse(Gate::forUser($reception)->allows('appointments.create', $b));
        $this->assertSame([$a->id], app(AccessControl::class)->locationIdsFor($reception, 'appointments.view'));
        $this->assertSame([], app(AccessControl::class)->locationIdsFor($reception, 'reports.financial'));
    }

    public function test_superadmin_has_all_locations_but_no_implicit_clinical_access(): void
    {
        $admin = $this->userWithRole('superadmin', all: true);
        $location = $this->makeLocation();

        $this->assertTrue($admin->hasPermission('system.updates'));
        $this->assertTrue($admin->hasPermission('reports.view', $location));
        $this->assertNull(app(AccessControl::class)->locationIdsFor($admin, 'reports.view'));
        foreach (PermissionCatalog::CLINICAL as $permission) {
            $this->assertFalse($admin->hasPermission($permission), $permission);
        }
    }

    public function test_marketing_and_site_editor_have_no_patient_access(): void
    {
        foreach (['marketing', 'site_editor'] as $role) {
            $user = $this->userWithRole($role, all: true);
            foreach (['patients.view', 'appointments.view', 'medical_records.view', 'medical_records.view_own', 'documents.view'] as $permission) {
                $this->assertFalse($user->hasPermission($permission), "{$role} {$permission}");
            }
        }
    }

    public function test_inactive_user_is_denied(): void
    {
        $user = $this->userWithRole('superadmin', all: true);
        $user->update(['is_active' => false]);

        $this->assertFalse($user->hasPermission('reports.view'));
    }

    public function test_seeder_is_idempotent_and_keeps_customised_roles(): void
    {
        $role = Role::where('key', 'reception')->first();
        $role->permissions()->detach();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(0, $role->permissions()->count());
        $this->assertSame(count(PermissionCatalog::all()), Permission::count());
    }
}
