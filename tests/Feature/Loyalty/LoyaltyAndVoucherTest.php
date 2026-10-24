<?php

namespace Tests\Feature\Loyalty;

use App\Modules\Booking\Models\Booking;
use App\Modules\Crm\Models\Customer;
use App\Modules\Loyalty\Models\LoyaltyLedgerEntry;
use App\Modules\Loyalty\Services\LoyaltyException;
use App\Modules\Loyalty\Services\LoyaltyService;
use App\Modules\Loyalty\Services\VoucherService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingFixtures;
use Tests\Support\RunsParallelProcesses;
use Tests\TestCase;

class LoyaltyAndVoucherTest extends TestCase
{
    use BookingFixtures, DatabaseMigrations, RunsParallelProcesses;

    private function loyalty(): LoyaltyService
    {
        return app(LoyaltyService::class);
    }

    private function vouchers(): VoucherService
    {
        return app(VoucherService::class);
    }

    public function test_points_are_awarded_once_per_completed_and_paid_booking(): void
    {
        $this->setting('loyalty.enabled', true);
        $this->setting('loyalty.points_per_ron', 1);
        $customer = Customer::factory()->create();
        $booking = $this->completedPaidBooking($customer, 25050);

        $first = $this->loyalty()->awardForBooking($booking);
        $second = $this->loyalty()->awardForBooking($booking);

        $this->assertSame(250, $first->delta);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(250, $this->loyalty()->balance($customer));
    }

    public function test_no_points_when_program_disabled_or_booking_not_paid(): void
    {
        $customer = Customer::factory()->create();
        $this->setting('loyalty.enabled', false);
        $this->assertNull($this->loyalty()->awardForBooking($this->completedPaidBooking($customer, 10000)));

        $this->setting('loyalty.enabled', true);
        $unpaid = $this->completedPaidBooking($customer, 10000);
        $unpaid->forceFill(['payment_status' => 'deposit_paid'])->save();
        $this->assertNull($this->loyalty()->awardForBooking($unpaid));
    }

    public function test_redeeming_more_points_than_balance_is_refused(): void
    {
        $customer = Customer::factory()->create();
        $this->loyalty()->post($customer, 100, 'adjust', 'seed');

        $this->expectException(LoyaltyException::class);
        $this->loyalty()->redeemPoints($customer, 101, 'too-much');
    }

    public function test_reversal_after_refund_never_takes_balance_below_zero(): void
    {
        $this->setting('loyalty.enabled', true);
        $customer = Customer::factory()->create();
        $booking = $this->completedPaidBooking($customer, 20000);
        $this->loyalty()->awardForBooking($booking);
        $this->loyalty()->redeemPoints($customer, 150, 'spent');

        $reversal = $this->loyalty()->reverseForBooking($booking);

        $this->assertSame(-50, $reversal->delta);
        $this->assertSame(0, $this->loyalty()->balance($customer));
    }

    public function test_expiry_consumes_oldest_points_first(): void
    {
        $customer = Customer::factory()->create();
        $now = CarbonImmutable::parse('2026-06-01 12:00:00');
        $this->loyalty()->post($customer, 100, 'earn', 'old', expiresAt: $now->subDay());
        $this->loyalty()->post($customer, 80, 'earn', 'new', expiresAt: $now->addYear());
        $this->loyalty()->redeemPoints($customer, 30, 'spent');

        $expired = $this->loyalty()->expireFor($customer, $now);

        $this->assertSame(-70, $expired->delta);
        $this->assertSame(80, $this->loyalty()->balance($customer));
        $this->assertNull($this->loyalty()->expireFor($customer, $now->addMinute()), 'O a doua rulare nu mai expiră nimic.');
    }

    public function test_partial_voucher_redemption_tracks_balance_and_closes_at_zero(): void
    {
        $voucher = $this->vouchers()->issue(['type' => 'value', 'initial_value_minor' => 20000]);

        $this->vouchers()->redeem($voucher->code, 12000, 'r1', null);
        $this->vouchers()->redeem($voucher->code, 8000, 'r2', null);

        $voucher->refresh();
        $this->assertSame(0, (int) $voucher->balance_minor);
        $this->assertSame('redeemed', $voucher->status);
        $this->expectException(LoyaltyException::class);
        $this->vouchers()->redeem($voucher->code, 1, 'r3', null);
    }

    public function test_expired_or_full_use_vouchers_are_refused(): void
    {
        $expired = $this->vouchers()->issue(['type' => 'value', 'initial_value_minor' => 5000, 'valid_until' => now()->subDay()->toDateString()]);
        $fullUse = $this->vouchers()->issue(['type' => 'value', 'initial_value_minor' => 5000, 'allows_partial_use' => false]);

        foreach ([[$expired, 1000, 'not_usable'], [$fullUse, 1000, 'full_use_only']] as [$voucher, $amount, $reason]) {
            try {
                $this->vouchers()->redeem($voucher->code, $amount, 'x-'.$voucher->id, null);
                $this->fail('Utilizarea trebuia refuzată.');
            } catch (LoyaltyException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
    }

    public function test_simultaneous_voucher_redemptions_cannot_exceed_balance(): void
    {
        $this->requiresRowLocks();
        $voucher = $this->vouchers()->issue(['type' => 'value', 'initial_value_minor' => 10000]);

        $results = $this->runInParallel(array_map(fn ($i) => ['voucher', $voucher->id, '4000', 'web', "v{$i}"], range(1, 6)));

        $ok = array_filter($results, fn ($r) => $r['ok'] ?? false);
        $this->assertCount(2, $ok, json_encode($results));
        $this->assertSame(2000, (int) $voucher->refresh()->balance_minor);
    }

    public function test_simultaneous_point_redemptions_cannot_exceed_balance(): void
    {
        $this->requiresRowLocks();
        $customer = Customer::factory()->create();
        $this->loyalty()->post($customer, 100, 'adjust', 'seed');

        $results = $this->runInParallel(array_map(fn ($i) => ['points', $customer->id, '30', 'web', "p{$i}"], range(1, 6)));

        $this->assertCount(3, array_filter($results, fn ($r) => $r['ok'] ?? false), json_encode($results));
        $this->assertSame(10, $this->loyalty()->balance($customer));
        $this->assertSame(10, (int) LoyaltyLedgerEntry::query()->where('customer_id', $customer->id)->sum('delta'));
    }

    private function requiresRowLocks(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Testul de concurență necesită MySQL/MariaDB.');
        }
    }

    private function completedPaidBooking(Customer $customer, int $paidMinor): Booking
    {
        $package = $this->package($this->arena());

        return Booking::query()->create([
            'code' => 'AW'.strtoupper(substr(md5((string) microtime(true)), 0, 7)),
            'package_id' => $package->id,
            'customer_id' => $customer->id,
            'participants' => 2,
            'status' => 'completed',
            'payment_status' => 'paid',
            'contact_name' => 'Test',
            'total_minor' => $paidMinor,
            'paid_minor' => $paidMinor,
        ])->load('customer');
    }
}
