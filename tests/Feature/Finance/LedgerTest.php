<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\ChargeService;
use App\Domain\Finance\FinanceException;
use App\Domain\Finance\Ledger;
use App\Domain\Orders\OrderReady;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\ClinicalFixtures;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use ClinicalFixtures, RefreshDatabase;

    private function order(?int $locationId = null): Order
    {
        $locationId ??= $this->makeLocation()->id;

        return app(OrderService::class)->create($this->makePatient(), ['location_id' => $locationId, 'due_on' => now()->addDays(7)->toDateString()], [
            ['kind' => 'frame', 'description' => 'Ramă DEMO', 'quantity' => 1, 'unit_price_cents' => 45000],
            ['kind' => 'lens', 'description' => 'Lentile DEMO', 'quantity' => 2, 'unit_price_cents' => 32550],
        ], $this->staff('reception'));
    }

    public function test_money_parsing_and_formatting_are_consistent(): void
    {
        $this->assertSame(123450, Money::parse('1.234,50'));
        $this->assertSame(123450, Money::parse('1234,5'));
        $this->assertSame(123450, Money::parse('1234.50'));
        $this->assertSame(100, Money::parse('1'));
        $this->assertSame('1.234,50 lei', Money::format(123450));
        $this->assertSame('−0,05 lei', Money::format(-5));
        $this->assertFalse(Money::isValid('12,345'));
        $this->assertFalse(Money::isValid('abc'));
    }

    public function test_order_total_number_and_status_history(): void
    {
        Event::fake([OrderReady::class]);
        $order = $this->order();
        $this->assertSame(110100, $order->total_cents);
        $this->assertMatchesRegularExpression('/^C\d{4}-\d{6}$/', $order->number);

        $user = $this->staff('reception');
        $service = app(OrderService::class);
        foreach (['lab', 'quality_check', 'ready'] as $status) {
            $order = $service->changeStatus($order, $status, $user);
        }
        Event::assertDispatched(OrderReady::class);
        $this->assertNotNull($order->ready_at);
        $this->assertSame(['status', 'status', 'status', 'created'], $order->events()->pluck('type')->all());

        try {
            $service->changeStatus($order, 'new', $user);
            $this->fail('Backward transition accepted');
        } catch (FinanceException) {
        }
    }

    public function test_advance_is_not_counted_twice_and_balance_is_exact(): void
    {
        $order = $this->order();
        $ledger = app(Ledger::class);
        $user = $this->staff('reception');

        $advance = $ledger->recordPayment($order, 30000, 'cash', $user);
        $this->assertTrue($advance->is_advance);
        $this->assertSame(['value' => 110100, 'paid' => 30000, 'balance' => 80100], $ledger->summary($order));

        $final = $ledger->recordPayment($order, 80100, 'card', $user);
        $this->assertFalse($final->is_advance);
        $this->assertSame(['value' => 110100, 'paid' => 110100, 'balance' => 0], $ledger->summary($order));

        $this->expectException(FinanceException::class);
        $ledger->recordPayment($order, 1, 'cash', $user);
    }

    public function test_refunds_and_voids_keep_the_journal(): void
    {
        $order = $this->order();
        $ledger = app(Ledger::class);
        $user = $this->staff('location_manager');
        $payment = $ledger->recordPayment($order, 50000, 'cash', $user);

        // A wrong entry is voided with a reason, never deleted.
        $ledger->void($payment, 'Sumă greșită introdusă', $user);
        $this->assertSame(1, Payment::count());
        $this->assertNotNull($payment->fresh()->voided_at);
        $this->assertSame(0, $ledger->paidCents($order));

        $ledger->recordPayment($order, 40000, 'card', $user);
        $ledger->recordRefund($order, 10000, 'card', $user, 'Reducere acordată ulterior');
        $this->assertSame(30000, $ledger->paidCents($order));
        try {
            $ledger->recordRefund($order, 30001, 'cash', $user, 'Prea mult');
            $this->fail('Refund above paid accepted');
        } catch (FinanceException) {
        }
    }

    public function test_cancelled_order_value_drops_and_paid_money_shows_as_credit(): void
    {
        $order = $this->order();
        $ledger = app(Ledger::class);
        $user = $this->staff('location_manager');
        $ledger->recordPayment($order, 20000, 'cash', $user);

        $order = app(OrderService::class)->changeStatus($order, 'cancelled', $user, 'Pacientul a renunțat');
        $this->assertSame(['value' => 0, 'paid' => 20000, 'balance' => -20000], $ledger->summary($order));

        $ledger->recordRefund($order, 20000, 'cash', $user, 'Restituire avans');
        $this->assertSame(0, $ledger->balanceCents($order));
    }

    public function test_delivery_with_balance_needs_explicit_confirmation(): void
    {
        $order = $this->order();
        $user = $this->staff('reception');
        $service = app(OrderService::class);
        foreach (['lab', 'quality_check', 'ready'] as $status) {
            $order = $service->changeStatus($order, $status, $user);
        }
        try {
            $service->changeStatus($order, 'delivered', $user);
            $this->fail('Delivered with balance');
        } catch (FinanceException) {
        }
        $order = $service->changeStatus($order, 'delivered', $user, null, allowUnpaid: true);
        $this->assertSame('delivered', $order->status);
        $this->assertStringContainsString('1.101,00 lei', $order->events()->first()->note);
    }

    public function test_editing_cannot_lower_the_total_below_what_was_paid(): void
    {
        $order = $this->order();
        app(Ledger::class)->recordPayment($order, 100000, 'card', $this->staff('reception'));

        $this->expectException(FinanceException::class);
        app(OrderService::class)->update($order, [], [['kind' => 'frame', 'description' => 'Ramă', 'quantity' => 1, 'unit_price_cents' => 50000]], $order->fresh()->lock_version, $this->staff('reception'));
    }

    public function test_service_charges_separate_value_from_cash(): void
    {
        $location = $this->makeLocation();
        $patient = $this->makePatient();
        $user = $this->staff('reception');
        $charge = app(ChargeService::class)->record($patient, $location->id, 'Consultație DEMO', 25000, now()->toDateString(), $user);
        $ledger = app(Ledger::class);
        $this->assertSame(['value' => 25000, 'paid' => 0, 'balance' => 25000], $ledger->summary($charge));

        $payment = $ledger->recordPayment($charge, 25000, 'card', $user, null, null, 'idem-1');
        $this->assertSame($payment->id, $ledger->recordPayment($charge, 25000, 'card', $user, null, null, 'idem-1')->id);
        $this->assertSame(0, $ledger->balanceCents($charge));

        try {
            app(ChargeService::class)->void($charge, 'Greșit', $user);
            $this->fail('Charge with money voided');
        } catch (FinanceException) {
        }
    }
}
