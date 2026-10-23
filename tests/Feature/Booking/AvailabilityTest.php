<?php

namespace Tests\Feature\Booking;

use App\Modules\Booking\Models\ResourceBlock;
use App\Modules\Booking\Services\AvailabilityService;
use App\Modules\Booking\Services\BookingData;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Core\Models\ScheduleException;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BookingFixtures;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use BookingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));
        $this->seedSchedule('10:00', '22:00');
        $this->setting('booking.slot_interval_minutes', 60);
        $this->setting('booking.min_lead_minutes', 60);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_slots_follow_opening_hours_and_slot_length(): void
    {
        $package = $this->package($this->arena());

        $result = app(AvailabilityService::class)->slotsFor($package, '2026-10-22', 4);

        $this->assertSame('ok', $result['status']);
        // 10:00 … 21:00 (ultimul slot de 60 de minute se termină la 22:00)
        $this->assertSame('10:00', $result['slots'][0]['local_time']);
        $this->assertSame('21:00', end($result['slots'])['local_time']);
        $this->assertCount(12, $result['slots']);
        $this->assertTrue(collect($result['slots'])->every(fn ($s) => $s['available']));
    }

    public function test_booked_slot_and_its_buffer_become_unavailable(): void
    {
        $package = $this->package($this->arena());
        $start = LocalTime::fromLocal('2026-10-22', '14:00');

        app(BookingService::class)->create(new BookingData(
            package: $package, startsAt: $start, participants: 4, contactName: 'Test Unu',
            contactEmail: 'unu@example.test', contactPhone: null, source: 'reception',
        ));

        $slots = collect(app(AvailabilityService::class)->slotsFor($package, '2026-10-22', 4)['slots'])->keyBy('local_time');

        $this->assertFalse($slots['14:00']['available']);
        // 15:00 este blocat de pauza de pregătire de 15 minute de după sesiune
        $this->assertFalse($slots['15:00']['available']);
        // 13:00 + 60 min + 15 min pregătire s-ar suprapune cu sesiunea de la 14:00
        $this->assertFalse($slots['13:00']['available']);
        $this->assertTrue($slots['12:00']['available']);
        $this->assertTrue($slots['16:00']['available']);
    }

    public function test_closed_day_exception_and_internal_blocks(): void
    {
        $arena = $this->arena();
        $package = $this->package($arena);

        ScheduleException::query()->create(['date' => '2026-10-23', 'is_closed' => true, 'reason' => 'Inventar']);
        $this->assertSame('closed', app(AvailabilityService::class)->slotsFor($package, '2026-10-23', 2)['status']);

        ResourceBlock::query()->create([
            'resource_id' => $arena->id,
            'starts_at' => LocalTime::fromLocal('2026-10-24', '10:00'),
            'ends_at' => LocalTime::fromLocal('2026-10-24', '12:00'),
            'type' => 'maintenance',
        ]);
        $slots = collect(app(AvailabilityService::class)->slotsFor($package, '2026-10-24', 2)['slots'])->keyBy('local_time');
        $this->assertFalse($slots['10:00']['available']);
        $this->assertFalse($slots['11:00']['available']);
        $this->assertTrue($slots['12:00']['available']);
    }

    public function test_lead_time_and_max_advance_window(): void
    {
        $package = $this->package($this->arena());
        // acum = 2026-10-20 11:00 ora locală (08:00 UTC); termen minim 60 min
        $slots = collect(app(AvailabilityService::class)->slotsFor($package, '2026-10-20', 2)['slots'])->keyBy('local_time');
        $this->assertFalse($slots['11:00']['available']);
        $this->assertSame('too_soon', $slots['11:00']['reason']);
        $this->assertTrue($slots['12:00']['available']);

        $this->assertSame('out_of_range', app(AvailabilityService::class)->slotsFor($package, '2027-06-01', 2)['status']);
        $this->assertSame('out_of_range', app(AvailabilityService::class)->slotsFor($package, '2026-10-19', 2)['status']);
    }

    public function test_dst_autumn_change_keeps_local_wall_clock_labels(): void
    {
        // 25 octombrie 2026: ora 04:00 EEST devine 03:00 EET. Programul 10–22 rămâne pe ceasul local.
        $package = $this->package($this->arena());
        $slots = app(AvailabilityService::class)->slotsFor($package, '2026-10-25', 2)['slots'];

        $this->assertSame('10:00', $slots[0]['local_time']);
        $this->assertSame('2026-10-25T08:00:00Z', $slots[0]['starts_at']); // UTC+2 după schimbare
        $this->assertCount(12, $slots);

        $before = app(AvailabilityService::class)->slotsFor($package, '2026-10-24', 2)['slots'];
        $this->assertSame('2026-10-24T07:00:00Z', $before[0]['starts_at']); // UTC+3 înainte de schimbare
    }

    public function test_dst_spring_change(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-03-20 08:00:00', 'UTC'));
        $package = $this->package($this->arena());
        // 28 martie 2027: 03:00 EET devine 04:00 EEST
        $slots = app(AvailabilityService::class)->slotsFor($package, '2027-03-28', 2)['slots'];
        $this->assertSame('10:00', $slots[0]['local_time']);
        $this->assertSame('2027-03-28T07:00:00Z', $slots[0]['starts_at']);
    }

    public function test_package_without_configured_duration_or_capacity_is_not_schedulable(): void
    {
        $package = $this->package($this->arena(), ['slot_minutes' => null, 'booking_mode' => 'request']);

        $this->assertSame('not_schedulable', app(AvailabilityService::class)->slotsFor($package, '2026-10-22', 2)['status']);
    }
}
