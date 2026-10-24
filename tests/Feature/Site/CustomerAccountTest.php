<?php

namespace Tests\Feature\Site;

use App\Modules\Booking\Models\Booking;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Models\CustomerLoginToken;
use Database\Seeders\BaseDataSeeder;
use Database\Seeders\NotificationTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BaseDataSeeder::class);
        $this->seed(NotificationTemplatesSeeder::class);
        config(['aw.messaging.force_sandbox' => true]);
    }

    private function tokenFor(Customer $customer, array $attributes = []): string
    {
        $token = Str::random(48);
        CustomerLoginToken::query()->create($attributes + ['customer_id' => $customer->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(30)]);

        return $token;
    }

    public function test_login_link_request_does_not_reveal_whether_the_address_exists(): void
    {
        $customer = Customer::factory()->create(['email' => 'ana@example.test', 'email_normalized' => 'ana@example.test']);

        $known = $this->post(route('forms.account-login'), ['email' => 'ana@example.test']);
        $unknown = $this->post(route('forms.account-login'), ['email' => 'nimeni@example.test']);

        $this->assertSame($known->headers->get('Location'), $unknown->headers->get('Location'));
        $this->assertSame(session('status'), __('site.account.login_sent'));
        $this->assertSame(1, CustomerLoginToken::query()->count());
        $this->assertSame($customer->id, CustomerLoginToken::query()->value('customer_id'));
    }

    public function test_login_link_works_once_and_expires(): void
    {
        $customer = Customer::factory()->create();
        $token = $this->tokenFor($customer);

        $this->get(route('account.consume', ['token' => $token]))->assertRedirect();
        $this->assertAuthenticatedAs($customer, 'customer');

        auth('customer')->logout();
        $this->get(route('account.consume', ['token' => $token]))->assertSessionHasErrors('email');
        $this->assertGuest('customer');

        $expired = $this->tokenFor($customer, ['expires_at' => now()->subMinute()]);
        $this->get(route('account.consume', ['token' => $expired]))->assertSessionHasErrors('email');
        $this->assertGuest('customer');
    }

    public function test_customer_cannot_act_on_someone_elses_booking(): void
    {
        $owner = Customer::factory()->create();
        $other = Customer::factory()->create();
        $booking = Booking::query()->create(['code' => 'AWTEST001', 'customer_id' => $owner->id, 'status' => 'confirmed', 'contact_name' => 'X', 'contact_email' => 'x@example.test', 'manage_token_hash' => hash('sha256', 'x')]);

        $this->actingAs($other, 'customer')->post(route('account.manage-link', $booking))->assertNotFound();
    }

    public function test_erasure_request_is_registered_once(): void
    {
        $customer = Customer::factory()->create();
        $this->actingAs($customer, 'customer')->post(route('account.erasure'))->assertRedirect();
        $this->actingAs($customer, 'customer')->post(route('account.erasure'))->assertRedirect();

        $this->assertDatabaseCount('data_requests', 1);
    }
}
