<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role, bool $withMfa = false): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        if ($withMfa) {
            $user->saveAppAuthenticationSecret((new Google2FA)->generateSecretKey());
        }

        return $user;
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin/bookings')->assertRedirect('/admin/login');
    }

    public function test_customers_role_cannot_enter_the_panel(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_inactive_staff_cannot_enter_the_panel(): void
    {
        $user = $this->user('reception');
        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_reception_sees_bookings_but_not_settings_integrations_or_finance(): void
    {
        $reception = $this->user('reception');

        $this->actingAs($reception)->get('/admin/bookings')->assertOk();
        $this->actingAs($reception)->get('/admin/check-in')->assertOk();
        $this->actingAs($reception)->get('/admin/settings')->assertForbidden();
        $this->actingAs($reception)->get('/admin/integrations')->assertForbidden();
        $this->actingAs($reception)->get('/admin/users')->assertForbidden();
        $this->actingAs($reception)->get('/admin/audit-logs')->assertForbidden();
    }

    public function test_editor_cannot_see_customers_or_bookings(): void
    {
        $editor = $this->user('editor');

        $this->actingAs($editor)->get('/admin/pages')->assertOk();
        $this->actingAs($editor)->get('/admin/customers')->assertForbidden();
        $this->actingAs($editor)->get('/admin/bookings')->assertForbidden();
    }

    public function test_roles_requiring_mfa_are_sent_to_setup_until_configured(): void
    {
        $manager = $this->user('manager');
        $this->actingAs($manager)->get('/admin/bookings')->assertRedirect('/admin/multi-factor-authentication/set-up');

        $withMfa = $this->user('manager', withMfa: true);
        $this->actingAs($withMfa)->get('/admin/bookings')->assertOk();
    }

    public function test_reception_is_not_forced_to_configure_mfa(): void
    {
        $this->actingAs($this->user('reception'))->get('/admin')->assertOk();
    }

    public function test_finance_screens_follow_role_permissions(): void
    {
        $accountant = $this->user('accountant', withMfa: true);
        foreach (['/admin/invoices', '/admin/payments', '/admin/refunds', '/admin/document-series'] as $url) {
            $this->actingAs($accountant)->get($url)->assertOk();
        }
        $this->actingAs($accountant)->get('/admin/invoices/create')->assertForbidden(); // contabilul nu emite, doar vede și transmite

        $reception = $this->user('reception');
        $this->actingAs($reception)->get('/admin/invoices/create')->assertOk();
        $this->actingAs($reception)->get('/admin/payments')->assertOk(); // recepția vede încasările (are finance.view)

        $editor = $this->user('editor');
        $this->actingAs($editor)->get('/admin/invoices')->assertForbidden();
    }
}
