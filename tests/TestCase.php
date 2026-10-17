<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use App\Support\Access\AccessControl;
use App\Support\Access\TwoFactor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Front-end assets are built at release time; tests render views without the Vite manifest.
        $this->withoutVite();
    }

    protected function staff(string $role, array $attributes = [], array $locationIds = []): User
    {
        if (! Role::query()->exists()) {
            $this->seed(RolesAndPermissionsSeeder::class);
        }
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::where('key', $role)->value('id'));
        $user->locations()->attach($locationIds);
        app(AccessControl::class)->flush();

        return $user;
    }

    /** Signed in, with the two-factor step already passed in this session. */
    protected function signIn(User $user): static
    {
        return $this->actingAs($user)->withSession([TwoFactor::SESSION_PASSED => true]);
    }
}
