<?php

namespace Tests\Feature\Auth;

use App\Support\Access\TwoFactor;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_bilingual(): void
    {
        $this->get('/crm/login')->assertOk()->assertSee('Autentificare')->assertSee('Intră în cont');
        $this->get('/crm/login?lang=hu')->assertOk()->assertSee('Bejelentkezés');
        // The guest choice is remembered for the next page.
        $this->get('/crm/forgot-password')->assertSee('Jelszó visszaállítása');
    }

    public function test_staff_can_sign_in_and_is_audited(): void
    {
        $user = $this->staff('reception', ['email' => 'receptie@example.test']);

        $this->post('/crm/login', ['email' => 'Receptie@Example.test', 'password' => 'password'])
            ->assertRedirect(route('crm.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'auth.login']);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_password_gives_generic_error_and_locks_out(): void
    {
        $this->staff('reception', ['email' => 'receptie@example.test']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/crm/login', ['email' => 'receptie@example.test', 'password' => 'wrong'])
                ->assertSessionHasErrors(['email' => 'Emailul sau parola nu sunt corecte.']);
        }
        // Unknown accounts get the same message.
        $this->post('/crm/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'Emailul sau parola nu sunt corecte.']);

        $response = $this->post('/crm/login', ['email' => 'receptie@example.test', 'password' => 'password']);
        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Prea multe încercări', session('errors')->first('email'));
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_locked']);
    }

    public function test_inactive_account_cannot_sign_in_or_keep_a_session(): void
    {
        $user = $this->staff('reception', ['email' => 'off@example.test', 'is_active' => false]);
        $this->post('/crm/login', ['email' => 'off@example.test', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $user->update(['is_active' => true]);
        $this->signIn($user)->get('/crm')->assertOk();
        $user->update(['is_active' => false]);
        $this->get('/crm')->assertRedirect(route('crm.login'));
        $this->assertGuest();
    }

    public function test_privileged_role_must_enrol_in_two_factor(): void
    {
        $admin = $this->staff('superadmin', ['email' => 'admin@example.test', 'all_locations' => true]);

        $this->post('/crm/login', ['email' => 'admin@example.test', 'password' => 'password'])->assertRedirect(route('crm.dashboard'));
        $this->get('/crm')->assertRedirect(route('crm.two-factor.setup'));
        $this->get('/admin/locations')->assertRedirect(route('crm.two-factor.setup'));

        $this->get('/crm/two-factor/setup')->assertOk()->assertSee('<svg', false);
        $secret = session('auth.two_factor_setup_secret');

        $this->post('/crm/two-factor/setup', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/crm/two-factor/setup', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect(route('crm.dashboard'));

        $this->assertNotNull($admin->fresh()->two_factor_confirmed_at);
        $this->get('/crm')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.two_factor_enabled']);
    }

    public function test_enrolled_user_must_pass_the_code_before_being_signed_in(): void
    {
        $secret = app(TwoFactor::class)->generateSecret();
        $user = $this->staff('reception', ['email' => 'otp@example.test']);
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        $this->post('/crm/login', ['email' => 'otp@example.test', 'password' => 'password'])->assertRedirect(route('crm.two-factor.challenge'));
        $this->assertGuest();

        $this->post('/crm/two-factor', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post('/crm/two-factor', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect(route('crm.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->get('/crm')->assertOk();
    }

    public function test_password_reset_does_not_reveal_accounts_and_works(): void
    {
        Notification::fake();
        $user = $this->staff('reception', ['email' => 'reset@example.test', 'locale' => 'hu']);

        $this->post('/crm/forgot-password', ['email' => 'nobody@example.test'])->assertSessionHas('status');
        Notification::assertNothingSent();

        $this->post('/crm/forgot-password', ['email' => 'reset@example.test'])->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);

        $token = Password::broker()->createToken($user);
        $this->post('/crm/reset-password', [
            'token' => $token, 'email' => 'reset@example.test',
            'password' => 'scurta', 'password_confirmation' => 'scurta',
        ])->assertSessionHasErrors('password');

        $this->post('/crm/reset-password', [
            'token' => $token, 'email' => 'reset@example.test',
            'password' => 'ParolaNoua2026', 'password_confirmation' => 'ParolaNoua2026',
        ])->assertRedirect(route('crm.login'));

        $this->post('/crm/login', ['email' => 'reset@example.test', 'password' => 'ParolaNoua2026'])->assertRedirect(route('crm.dashboard'));
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/crm')->assertRedirect(route('crm.login'));
        $this->get('/admin/specialists')->assertRedirect(route('crm.login'));
    }
}
