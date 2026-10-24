<?php

namespace Tests\Feature\Finance;

use App\Http\Middleware\AuthenticateFiscalAgent;
use App\Models\User;
use App\Modules\Booking\Services\BookingData;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Finance\Models\FiscalDevice;
use App\Modules\Finance\Models\FiscalReceipt;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\Fiscal\FiscalException;
use App\Modules\Finance\Services\Fiscal\FiscalReceiptService;
use App\Modules\Finance\Services\Payments\PaymentService;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Database\Seeders\BaseDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BookingFixtures;
use Tests\TestCase;

class FiscalTest extends TestCase
{
    use BookingFixtures, RefreshDatabase;

    private const SECRET = 'secret-agent-fictiv-0123456789';

    private User $reception;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));
        $this->seed(BaseDataSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seedSchedule();
        $this->setting('booking.auto_confirm', true);
        $this->setting('booking.min_lead_minutes', 60);
        $this->setting('company.vat_payer', 'yes');
        $this->publishPolicies();
        $this->reception = tap(User::factory()->create(), fn ($user) => $user->assignRole('reception'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function payment(): Payment
    {
        $booking = app(BookingService::class)->create(new BookingData(
            package: $this->package($this->arena()),
            startsAt: LocalTime::fromLocal('2026-10-22', '15:00'),
            participants: 2,
            contactName: 'Client Fictiv',
            contactEmail: 'client@example.test',
            contactPhone: '0700000000',
            source: 'reception',
            acceptedPolicyTypes: ['terms', 'booking_policy'],
            ageGroups: ['adults' => 2],
        ))['booking'];

        return app(PaymentService::class)->recordManual($booking, 'cash', 10000, $this->reception);
    }

    private function agentDevice(): FiscalDevice
    {
        return FiscalDevice::query()->create(['name' => 'Casa recepție', 'driver' => 'agent', 'is_active' => true, 'agent_key_id' => 'fa_test', 'agent_secret' => self::SECRET, 'vat_mapping' => ['21' => 'A'], 'payment_mapping' => ['cash' => '0']]);
    }

    private function agent(string $method, string $path, array $body = [], ?string $secret = null, ?int $timestamp = null): TestResponse
    {
        $payload = $method === 'GET' ? '' : json_encode($body);
        $timestamp ??= time();
        $signature = hash_hmac('sha256', AuthenticateFiscalAgent::canonical($timestamp, $method, $path, $payload), $secret ?? self::SECRET);

        return $this->call($method, $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AW_KEY' => 'fa_test', 'HTTP_X_AW_TIMESTAMP' => (string) $timestamp, 'HTTP_X_AW_SIGNATURE' => $signature,
        ], $payload);
    }

    public function test_simulator_records_are_clearly_not_fiscal_and_only_one_per_payment(): void
    {
        FiscalDevice::query()->create(['name' => 'Simulator', 'driver' => 'simulator', 'is_active' => true]);
        $payment = $this->payment();

        $receipt = app(FiscalReceiptService::class)->forPayment($payment, null, $this->reception->id);

        $this->assertTrue($receipt->is_simulated);
        $this->assertSame('printed', $receipt->status);
        $this->assertStringStartsWith('SIM-', $receipt->fiscal_number);
        $this->assertSame(10000, $receipt->total_minor);
        $this->assertSame('21.00', $receipt->items[0]['vat_rate']);

        $this->expectException(FiscalException::class);
        app(FiscalReceiptService::class)->forPayment($payment, null, $this->reception->id);
    }

    public function test_agent_claims_each_command_once_and_reports_result_idempotently(): void
    {
        $device = $this->agentDevice();
        $receipt = app(FiscalReceiptService::class)->forPayment($this->payment(), $device, $this->reception->id);
        $this->assertSame('queued', $receipt->status);

        $first = $this->agent('GET', '/api/fiscal-agent/commands')->assertOk()->json('commands');
        $this->assertCount(1, $first);
        $this->assertSame('A', $first[0]['items'][0]['vat_group']);
        $this->assertSame('0', $first[0]['payments'][0]['code']);
        $this->assertCount(0, $this->agent('GET', '/api/fiscal-agent/commands')->json('commands'), 'O comandă preluată nu se mai livrează.');

        $result = ['status' => 'printed', 'fiscal_number' => '0001234', 'z_report_number' => '0101'];
        $this->agent('POST', '/api/fiscal-agent/commands/'.$receipt->command_id.'/result', $result)->assertOk();
        $this->agent('POST', '/api/fiscal-agent/commands/'.$receipt->command_id.'/result', ['status' => 'failed', 'error' => 'întârziat'])->assertOk();

        $receipt->refresh();
        $this->assertSame('printed', $receipt->status, 'Un rezultat ulterior nu suprascrie bonul tipărit.');
        $this->assertSame('0001234', $receipt->fiscal_number);
        $this->assertNotNull($device->fresh()->last_seen_at);
    }

    public function test_agent_requests_need_a_valid_fresh_signature(): void
    {
        $this->agentDevice();
        $this->agent('GET', '/api/fiscal-agent/commands', [], 'alt-secret')->assertUnauthorized();
        $this->agent('GET', '/api/fiscal-agent/commands', [], null, time() - 400)->assertUnauthorized();
        $this->agent('POST', '/api/fiscal-agent/heartbeat', ['agent_version' => '1.0'])->assertOk();
    }

    public function test_dispatched_without_result_becomes_uncertain_and_is_reconciled_before_any_retry(): void
    {
        $device = $this->agentDevice();
        $receipt = app(FiscalReceiptService::class)->forPayment($this->payment(), $device, $this->reception->id);
        $this->agent('GET', '/api/fiscal-agent/commands')->assertOk();

        $this->travel(5)->minutes();
        $this->artisan('aw:fiscal-timeouts')->assertSuccessful();
        $this->assertSame('uncertain', $receipt->fresh()->status);

        try {
            app(FiscalReceiptService::class)->retry($receipt->fresh(), $this->reception->id);
            $this->fail('Un bon incert nu se reemite înainte de verificare.');
        } catch (FiscalException) {
        }

        $accountant = tap(User::factory()->create(), fn ($user) => $user->assignRole('accountant'));
        app(FiscalReceiptService::class)->reconcile($receipt, false, null, 'Verificat în jurnalul electronic: bonul lipsește.', $accountant->id);
        $retry = app(FiscalReceiptService::class)->retry($receipt->fresh(), $this->reception->id);

        $this->assertSame($receipt->id, $retry->retry_of_id);
        $this->assertSame('queued', $retry->status);
        $this->assertSame(2, FiscalReceipt::query()->count());
        $this->expectException(FiscalException::class);
        app(FiscalReceiptService::class)->retry($receipt->fresh(), $this->reception->id);
    }

    public function test_bank_transfers_do_not_get_fiscal_receipts(): void
    {
        FiscalDevice::query()->create(['name' => 'Simulator', 'driver' => 'simulator', 'is_active' => true]);
        $transfer = $this->payment()->replicate()->fill(['uuid' => (string) Str::uuid(), 'method' => 'bank_transfer']);
        $transfer->save();

        $this->expectException(FiscalException::class);
        app(FiscalReceiptService::class)->forPayment($transfer, null, $this->reception->id);
    }
}
