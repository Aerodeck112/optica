<?php

namespace Tests\Feature\Finance;

use App\Domain\Clinical\PrescriptionService;
use App\Domain\Finance\Ledger;
use App\Livewire\Crm\OrderEditor;
use App\Livewire\Crm\Patient\FinanceTab;
use App\Livewire\Crm\PaymentIndex;
use App\Livewire\Crm\PaymentsPanel;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ServiceCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ClinicalFixtures;
use Tests\TestCase;

class OrdersScreensTest extends TestCase
{
    use ClinicalFixtures, RefreshDatabase;

    private function createOrder($location, $patient): Order
    {
        Livewire::test(OrderEditor::class)
            ->call('choosePatient', $patient->id)
            ->set('locationId', $location->id)
            ->set('dueOn', now()->addDays(5)->toDateString())
            ->set('items.0.description', 'Ramă DEMO')->set('items.0.price', '450')
            ->set('items.1.description', 'Lentile DEMO')->set('items.1.price', '325,50')
            ->call('save')->assertHasNoErrors()->assertRedirect();

        return Order::latest('id')->firstOrFail();
    }

    public function test_reception_creates_an_order_records_an_advance_and_hands_it_over(): void
    {
        $location = $this->makeLocation();
        $patient = $this->makePatient();
        $this->signIn($this->staff('reception', [], [$location->id]));

        $order = $this->createOrder($location, $patient);
        $this->assertSame(110100, $order->total_cents);

        $panel = Livewire::test(PaymentsPanel::class, ['payableType' => 'order', 'payableId' => $order->id])
            ->call('open', 'payment')->assertSet('amount', '1101,00')
            ->set('amount', '300')->set('method', 'cash')->call('save')->assertHasNoErrors();
        // A second click with the same form does not record the money twice.
        $panel->call('save');
        $this->assertSame(1, Payment::count());
        $this->assertTrue(Payment::first()->is_advance);

        $editor = Livewire::test(OrderEditor::class, ['order' => $order]);
        foreach (['lab', 'quality_check', 'ready'] as $status) {
            $editor->set('nextStatus', $status)->call('changeStatus')->assertSet('failure', null);
        }
        $editor->set('nextStatus', 'delivered')->call('changeStatus')->assertSet('failure', __('orders.errors.unpaid', ['balance' => '801,00 lei']));

        Livewire::test(PaymentsPanel::class, ['payableType' => 'order', 'payableId' => $order->id])->call('open', 'payment')->call('save');
        $editor->set('nextStatus', 'delivered')->call('changeStatus')->assertSet('failure', null);
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame(110100, app(Ledger::class)->paidCents($order));

        $this->get(route('crm.orders.handover', $order))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_reception_sees_the_prescription_reference_but_not_its_values(): void
    {
        $location = $this->makeLocation();
        $patient = $this->makePatient();
        $doctor = $this->doctor($location);
        $rx = app(PrescriptionService::class)->issue($patient, 'glasses', ['od' => ['sph' => '-4.75']], $doctor, $location->id);

        $this->signIn($this->staff('reception', [], [$location->id]));
        Livewire::test(OrderEditor::class)->call('choosePatient', $patient->id)->set('prescriptionId', $rx->id)
            ->assertDontSee('-4.75')->assertSee(__('clinical.rx.types.glasses'));

        $this->signIn($doctor);
        Livewire::test(OrderEditor::class)->call('choosePatient', $patient->id)->set('prescriptionId', $rx->id)->assertSee('-4.75');
    }

    public function test_orders_and_payments_are_limited_to_the_user_locations(): void
    {
        $mine = $this->makeLocation();
        $other = $this->makeLocation();
        $patient = $this->makePatient();
        $this->signIn($this->staff('reception', [], [$other->id]));
        $order = $this->createOrder($other, $patient);

        $this->signIn($this->staff('reception', [], [$mine->id]));
        $this->get(route('crm.orders.edit', $order))->assertForbidden();
        $this->get(route('crm.orders.handover', $order))->assertForbidden();
        Livewire::test(PaymentsPanel::class, ['payableType' => 'order', 'payableId' => $order->id])->assertForbidden();

        // Reception may take payments but not refund or void them.
        $this->signIn($this->staff('reception', [], [$other->id]));
        Livewire::test(PaymentsPanel::class, ['payableType' => 'order', 'payableId' => $order->id])->call('open', 'refund')->assertForbidden();

        $this->signIn($this->staff('marketing'));
        $this->get(route('crm.orders.index'))->assertForbidden();
        $this->get(route('crm.payments.index'))->assertForbidden();
    }

    public function test_service_charge_from_appointment_and_cash_journal(): void
    {
        $location = $this->makeLocation();
        $patient = $this->makePatient();
        $doctor = $this->doctor($location);
        $appointment = $this->appointmentFor($patient, $doctor->specialist, $location, 'completed');
        $appointment->service->update(['default_price' => 250]);
        $this->signIn($this->staff('reception', [], [$location->id]));

        Livewire::test(FinanceTab::class, ['patientId' => $patient->id])->call('startAdd')
            ->set('appointmentId', $appointment->id)->assertSet('amount', '250,00')
            ->set('performedOn', now('Europe/Bucharest')->toDateString())->call('add')->assertHasNoErrors();
        $charge = ServiceCharge::firstOrFail();
        $this->assertSame($appointment->id, $charge->appointment_id);

        Livewire::test(PaymentsPanel::class, ['payableType' => 'service_charge', 'payableId' => $charge->id])
            ->call('open', 'payment')->set('method', 'card')->call('save')->assertHasNoErrors();

        Livewire::test(PaymentIndex::class)->assertSee('250,00 lei')->assertSee(__('finance.methods.card'));
    }

    public function test_voids_need_the_refund_right_and_keep_the_row(): void
    {
        $location = $this->makeLocation();
        $patient = $this->makePatient();
        $this->signIn($this->staff('reception', [], [$location->id]));
        $order = $this->createOrder($location, $patient);
        $payment = app(Ledger::class)->recordPayment($order, 10000, 'cash', $this->staff('reception'));

        Livewire::test(PaymentsPanel::class, ['payableType' => 'order', 'payableId' => $order->id])->call('startVoid', $payment->id)->assertForbidden();

        $this->signIn($this->staff('location_manager', [], [$location->id]));
        Livewire::test(PaymentsPanel::class, ['payableType' => 'order', 'payableId' => $order->id])
            ->call('startVoid', $payment->id)->set('voidReason', 'Introdus pe comanda greșită')->call('void')->assertHasNoErrors();
        $this->assertNotNull($payment->fresh()->voided_at);
        $this->assertSame(1, Payment::count());
    }
}
