<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\RoleEditor;
use App\Livewire\Admin\UserEditor;
use App\Models\Role;
use App\Models\User;
use App\Notifications\StaffInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class UserAdminTest extends TestCase
{
    use RefreshDatabase, SchedulingFixtures;

    public function test_only_user_managers_reach_the_pages(): void
    {
        $manager = $this->staff('location_manager');
        $this->signIn($manager)->get('/admin/users')->assertForbidden();
        $this->signIn($manager)->get('/admin/roles')->assertForbidden();
        Livewire::actingAs($manager)->test(UserEditor::class)->assertForbidden();

        $admin = $this->staff('superadmin', ['all_locations' => true]);
        $this->signIn($admin)->get('/admin/users')->assertOk()->assertSee($manager->email);
        $this->signIn($admin)->get('/admin/roles')->assertOk()->assertSee('Recepție');
    }

    public function test_creating_a_user_assigns_rights_and_sends_invitation_in_their_language(): void
    {
        Notification::fake();
        $admin = $this->staff('superadmin', ['all_locations' => true]);
        $location = $this->makeLocation();
        $specialist = $this->makeSpecialist();

        Livewire::actingAs($admin)->test(UserEditor::class)
            ->set('name', 'Recepționer Demo')
            ->set('email', 'Receptie.Demo@Example.test')
            ->set('locale', 'hu')
            ->set('roleIds', [Role::where('key', 'specialist')->value('id')])
            ->set('locationIds', [$location->id])
            ->set('specialistId', $specialist->id)
            ->call('save')
            ->assertHasNoErrors();

        $user = User::firstWhere('email', 'receptie.demo@example.test');
        $this->assertSame('hu', $user->locale);
        $this->assertTrue($user->hasPermission('appointments.view', $location));
        $this->assertSame($user->id, $specialist->fresh()->user_id);
        Notification::assertSentTo($user, StaffInvitation::class, function ($notification, $channels, $notifiable) {
            return str_contains($notification->toMail($notifiable)->subject, 'Optica Optofarm fiókja');
        });
        $this->assertDatabaseHas('audit_logs', ['action' => 'users.rights_changed', 'subject_id' => $user->id]);
    }

    public function test_invitation_link_sets_the_password(): void
    {
        Notification::fake();
        $admin = $this->staff('superadmin', ['all_locations' => true]);
        Livewire::actingAs($admin)->test(UserEditor::class)
            ->set('name', 'Nou Demo')->set('email', 'nou@example.test')
            ->set('roleIds', [Role::where('key', 'reception')->value('id')])
            ->call('save');
        $user = User::firstWhere('email', 'nou@example.test');

        $token = null;
        Notification::assertSentTo($user, StaffInvitation::class, function ($n, $c, $notifiable) use (&$token) {
            preg_match('#reset-password/([^?]+)#', $n->toMail($notifiable)->actionUrl, $m);
            $token = $m[1];

            return true;
        });
        // Still valid after 2 days (a normal reset link would have expired).
        $this->travel(48)->hours();
        auth()->logout();
        $this->post('/crm/reset-password', [
            'token' => $token, 'email' => 'nou@example.test', 'broker' => 'invites',
            'password' => 'PrimaParola2026', 'password_confirmation' => 'PrimaParola2026',
        ])->assertRedirect(route('crm.login'));
        $this->post('/crm/login', ['email' => 'nou@example.test', 'password' => 'PrimaParola2026'])->assertRedirect(route('crm.dashboard'));
    }

    public function test_cannot_remove_the_last_superadmin_or_deactivate_self(): void
    {
        $admin = $this->staff('superadmin', ['all_locations' => true]);

        Livewire::actingAs($admin)->test(UserEditor::class, ['user' => $admin])
            ->set('roleIds', [Role::where('key', 'reception')->value('id')])
            ->call('save')
            ->assertHasErrors('roleIds');
        Livewire::actingAs($admin)->test(UserEditor::class, ['user' => $admin])
            ->set('is_active', false)
            ->call('save')
            ->assertHasErrors('is_active');
        $this->assertTrue($admin->fresh()->hasPermission('users.manage'));
    }

    public function test_deactivation_and_2fa_reset_close_sessions(): void
    {
        $admin = $this->staff('superadmin', ['all_locations' => true]);
        $target = $this->staff('reception');
        $target->forceFill(['two_factor_secret' => 'ABCDEFGHIJKLMNOP', 'two_factor_confirmed_at' => now()])->save();
        DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);
        config(['session.driver' => 'database']);

        Livewire::actingAs($admin)->test(UserEditor::class, ['user' => $target])->call('resetTwoFactor');
        $this->assertNull($target->fresh()->two_factor_confirmed_at);
        $this->assertDatabaseMissing('sessions', ['id' => 'sess-1']);

        DB::table('sessions')->insert(['id' => 'sess-2', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);
        Livewire::actingAs($admin)->test(UserEditor::class, ['user' => $target])->set('is_active', false)->call('save')->assertHasNoErrors();
        $this->assertDatabaseMissing('sessions', ['id' => 'sess-2']);
        $this->assertFalse($target->fresh()->hasPermission('appointments.view'));
    }

    public function test_role_permissions_change_immediately_and_are_audited(): void
    {
        $admin = $this->staff('superadmin', ['all_locations' => true]);
        $reception = $this->staff('reception', ['all_locations' => true]);
        $this->assertFalse($reception->hasPermission('reports.view'));
        $role = Role::firstWhere('key', 'reception');

        $permissions = $role->permissions()->pluck('key')->push('reports.view')->all();
        Livewire::actingAs($admin)->test(RoleEditor::class, ['role' => $role])
            ->set('permissions', $permissions)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($reception->fresh()->hasPermission('reports.view'));
        $log = DB::table('audit_logs')->where('action', 'roles.updated')->first();
        $this->assertSame(['reports.view'], json_decode($log->meta, true)['added']);
    }

    public function test_superadmin_role_keeps_user_management_and_custom_roles_can_be_deleted(): void
    {
        $admin = $this->staff('superadmin', ['all_locations' => true]);
        Livewire::actingAs($admin)->test(RoleEditor::class, ['role' => Role::firstWhere('key', 'superadmin')])
            ->set('permissions', ['reports.view'])
            ->call('save')
            ->assertHasErrors('permissions');

        Livewire::actingAs($admin)->test(RoleEditor::class)
            ->set('name.ro', 'Optician vânzări')
            ->set('permissions', ['orders.view', 'orders.manage'])
            ->call('save')
            ->assertHasNoErrors();
        $custom = Role::where('is_system', false)->firstOrFail();
        $this->assertSame('Optician vânzări', $custom->label('hu'));

        Livewire::actingAs($admin)->test(RoleEditor::class, ['role' => $custom])->call('delete');
        $this->assertDatabaseMissing('roles', ['id' => $custom->id]);
    }
}
