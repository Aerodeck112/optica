<?php

namespace Tests\Feature\Reports;

use App\Domain\Finance\ChargeService;
use App\Domain\Finance\Ledger;
use App\Domain\Orders\OrderService;
use App\Domain\Reports\ReportEngine;
use App\Domain\Reports\ReportFilters;
use App\Models\Appointment;
use App\Models\AppointmentEvent;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ClinicalFixtures;
use Tests\TestCase;

class ReportEngineTest extends TestCase
{
    use ClinicalFixtures, RefreshDatabase;

    private const TZ = 'Europe/Bucharest';

    private function appt($patient, $specialist, $location, string $local, string $status, array $extra = []): Appointment
    {
        $start = CarbonImmutable::parse($local, self::TZ);
        $service = $extra['service'] ?? $this->makeService();
        unset($extra['service']);

        return Appointment::create($extra + [
            'uuid' => (string) Str::uuid(), 'patient_id' => $patient?->id, 'location_id' => $location->id, 'specialist_id' => $specialist->id,
            'service_id' => $service->id, 'starts_at' => $start, 'ends_at' => $start->addMinutes(30), 'block_starts_at' => $start, 'block_ends_at' => $start->addMinutes(30),
            'status' => $status, 'source' => 'reception', 'locale' => 'ro',
        ]);
    }

    private function filters(array $extra = []): ReportFilters
    {
        return ReportFilters::fromArray($extra + ['from' => '2026-11-01', 'to' => '2026-11-30', 'compare' => 'none']);
    }

    public function test_a_specialist_in_two_locations_and_unique_patients_are_never_double_counted(): void
    {
        $a = $this->makeLocation();
        $b = $this->makeLocation();
        $doctor = $this->makeSpecialist();
        $patient = $this->makePatient();
        $other = $this->makePatient();
        $this->appt($patient, $doctor, $a, '2026-11-03 10:00', 'completed');
        $this->appt($patient, $doctor, $b, '2026-11-10 10:00', 'completed');
        $this->appt($other, $doctor, $b, '2026-11-11 10:00', 'confirmed');
        $this->appt($other, $doctor, $b, '2026-11-12 10:00', 'held');
        foreach ([[$patient, $a, '2026-11-03 10:00'], [$patient, $b, '2026-11-10 10:00']] as [$p, $l, $t]) {
            Visit::create(['patient_id' => $p->id, 'location_id' => $l->id, 'specialist_id' => $doctor->id, 'started_at' => CarbonImmutable::parse($t, self::TZ)]);
        }

        $engine = app(ReportEngine::class);
        $user = $this->staff('superadmin', ['all_locations' => true]);
        $summary = $engine->summary($this->filters(), $user);
        $this->assertSame(3, $summary['appointments']);
        $this->assertSame(2, $summary['visits']);
        $this->assertSame(1, $summary['unique_patients']);
        $this->assertSame(2, $summary['unique_booked_patients']);

        $rows = collect($engine->byDimension($this->filters(), $user, 'location'));
        $this->assertSame(3, $rows->sum('total'));
        // Per-location patient counts (1 + 2) are not the total (2).
        $this->assertSame(3, $rows->sum('patients'));
        $this->assertSame(1, collect($engine->byDimension($this->filters(), $user, 'specialist'))->count());
    }

    public function test_rates_show_their_denominators(): void
    {
        $location = $this->makeLocation();
        $doctor = $this->makeSpecialist();
        foreach (['completed', 'completed', 'no_show', 'cancelled', 'confirmed'] as $i => $status) {
            $this->appt($this->makePatient(), $doctor, $location, '2026-11-0'.($i + 2).' 09:00', $status);
        }
        $summary = app(ReportEngine::class)->summary($this->filters(), $this->staff('superadmin', ['all_locations' => true]));

        $this->assertSame(5, $summary['appointments']);
        $this->assertSame(20.0, $summary['cancel_rate']);
        $this->assertSame(3, $summary['no_show_due']);
        $this->assertSame(33.3, $summary['no_show_rate']);
    }

    public function test_date_basis_reschedules_and_new_patients(): void
    {
        $location = $this->makeLocation();
        $doctor = $this->makeSpecialist();
        $patient = $this->makePatient();
        $first = $this->appt($patient, $doctor, $location, '2026-10-20 10:00', 'completed', ['created_at' => CarbonImmutable::parse('2026-10-01 09:00', self::TZ)]);
        $second = $this->appt($patient, $doctor, $location, '2026-11-20 10:00', 'confirmed', ['created_at' => CarbonImmutable::parse('2026-11-05 09:00', self::TZ)]);
        $newcomer = $this->appt($this->makePatient(), $doctor, $location, '2026-11-21 10:00', 'confirmed', ['created_at' => CarbonImmutable::parse('2026-10-15 09:00', self::TZ)]);
        AppointmentEvent::create(['appointment_id' => $second->id, 'type' => 'rescheduled', 'actor' => 'user', 'created_at' => CarbonImmutable::parse('2026-11-06 12:00', self::TZ)]);
        AppointmentEvent::create(['appointment_id' => $second->id, 'type' => 'rescheduled', 'actor' => 'user', 'created_at' => CarbonImmutable::parse('2026-11-07 12:00', self::TZ)]);

        $engine = app(ReportEngine::class);
        $user = $this->staff('superadmin', ['all_locations' => true]);
        $this->assertSame(2, $engine->summary($this->filters(), $user)['appointments']);
        $this->assertSame(1, $engine->summary($this->filters(['basis' => 'created']), $user)['appointments']);
        $this->assertSame(2, $engine->summary($this->filters(), $user)['reschedules']);
        $this->assertSame([$newcomer->id], $engine->appointments($this->filters(['patient_type' => 'new']), $user)->pluck('id')->all());
        $this->assertSame([$second->id], $engine->appointments($this->filters(['patient_type' => 'returning']), $user)->pluck('id')->all());
    }

    public function test_managers_see_only_their_locations(): void
    {
        $mine = $this->makeLocation();
        $other = $this->makeLocation();
        $doctor = $this->makeSpecialist();
        $this->appt($this->makePatient(), $doctor, $mine, '2026-11-03 10:00', 'confirmed');
        $this->appt($this->makePatient(), $doctor, $other, '2026-11-03 11:00', 'confirmed');
        $manager = $this->staff('location_manager', [], [$mine->id]);

        $this->assertSame(1, app(ReportEngine::class)->summary($this->filters(), $manager)['appointments']);
        $this->assertSame(1, app(ReportEngine::class)->summary($this->filters(['locations' => [$other->id, $mine->id]]), $manager)['appointments']);
    }

    public function test_occupancy_uses_working_time_after_exceptions(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', self::TZ));
        $location = $this->makeLocation();
        $doctor = $this->makeSpecialist();
        $this->makeSchedule($location, $doctor, [1 => [['09:00', '13:00']]]);
        $this->appt($this->makePatient(), $doctor, $location, '2026-11-02 09:00', 'completed');
        $this->appt($this->makePatient(), $doctor, $location, '2026-11-02 09:30', 'cancelled');

        $rows = app(ReportEngine::class)->occupancy($this->filters(['from' => '2026-11-02', 'to' => '2026-11-08']), $this->staff('superadmin', ['all_locations' => true]));
        $this->assertSame([['location_id' => $location->id, 'specialist_id' => $doctor->id, 'available' => 240, 'booked' => 30, 'rate' => 12.5]], $rows);
    }

    public function test_financial_figures_are_kept_apart(): void
    {
        $location = $this->makeLocation();
        $patient = $this->makePatient();
        $user = $this->staff('location_manager', [], [$location->id]);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', self::TZ));
        $charge = app(ChargeService::class)->record($patient, $location->id, 'Consultație', 20000, '2026-11-10', $user);
        $order = app(OrderService::class)->create($patient, ['location_id' => $location->id], [['kind' => 'frame', 'description' => 'Ramă', 'quantity' => 1, 'unit_price_cents' => 50000]], $user);
        $ledger = app(Ledger::class);
        $ledger->recordPayment($charge, 20000, 'card', $user);
        $ledger->recordPayment($order, 10000, 'cash', $user);
        $ledger->recordRefund($charge, 5000, 'card', $user, 'Reducere');

        $money = app(ReportEngine::class)->financial($this->filters(), $user);
        $this->assertSame(20000, $money['services_value']);
        $this->assertSame(0, $money['orders_value']);
        $this->assertSame(30000, $money['received']);
        $this->assertSame(10000, $money['advances']);
        $this->assertSame(5000, $money['refunds']);
        $this->assertSame(['card' => 15000, 'cash' => 10000], $money['by_method']);
        $this->assertSame(40000 + 5000, $money['outstanding_now']);
    }
}
