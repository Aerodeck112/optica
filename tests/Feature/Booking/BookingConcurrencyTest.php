<?php

namespace Tests\Feature\Booking;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\BookingResource;
use App\Modules\Booking\Models\SlotHold;
use App\Support\LocalTime;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingFixtures;
use Tests\Support\RunsParallelProcesses;
use Tests\TestCase;

/**
 * Concurență reală: procese PHP separate, conexiuni separate la MariaDB/MySQL, pornite simultan.
 * Nu rulează pe SQLite (acolo nu există blocări de rând).
 */
class BookingConcurrencyTest extends TestCase
{
    use BookingFixtures, DatabaseMigrations, RunsParallelProcesses;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Testul de concurență necesită MySQL/MariaDB.');
        }
        $this->seedSchedule('10:00', '22:00');
        $this->setting('booking.slot_interval_minutes', 60);
        $this->setting('booking.min_lead_minutes', 0);
        $this->setting('booking.auto_confirm', true);
        $this->publishPolicies();
    }

    public function test_many_simultaneous_requests_for_the_last_slot_produce_exactly_one_booking(): void
    {
        $package = $this->package($this->arena());
        $start = LocalTime::fromLocal(LocalTime::now()->addDays(3)->toDateString(), '15:00');

        $sources = ['web', 'web', 'ai', 'reception', 'web', 'ai', 'web', 'reception', 'web', 'web'];
        $results = $this->runInParallel(array_map(
            fn ($source, $i) => ['create', $package->id, $start->toIso8601String(), $source, "c{$i}"],
            $sources,
            array_keys($sources),
        ));

        $successes = array_filter($results, fn ($r) => $r['ok'] ?? false);
        $this->assertCount(1, $successes, 'Exact o rezervare trebuie să reușească. Rezultate: '.json_encode($results));
        foreach ($results as $result) {
            if (! ($result['ok'] ?? false)) {
                $this->assertSame('slot_unavailable', $result['reason'], json_encode($result));
            }
        }

        $this->assertSame(1, Booking::query()->count());
        $this->assertSame(1, BookingResource::query()->where('is_active', true)->count());
    }

    public function test_simultaneous_holds_for_the_same_slot_produce_exactly_one_hold(): void
    {
        $package = $this->package($this->arena());
        $start = LocalTime::fromLocal(LocalTime::now()->addDays(4)->toDateString(), '12:00');

        $results = $this->runInParallel(array_map(
            fn ($i) => ['hold', $package->id, $start->toIso8601String(), 'web', "h{$i}"],
            range(1, 8),
        ));

        $this->assertCount(1, array_filter($results, fn ($r) => $r['ok'] ?? false), json_encode($results));
        $this->assertSame(1, SlotHold::query()->count());
    }

    public function test_shared_sessions_never_exceed_capacity_under_concurrency(): void
    {
        // sesiune partajată: capacitate 10, fiecare proces cere 4 locuri → maxim 2 reușesc
        $package = $this->package($this->arena(['capacity' => 10]), ['session_mode' => 'shared']);
        $start = LocalTime::fromLocal(LocalTime::now()->addDays(5)->toDateString(), '18:00');

        $results = $this->runInParallel(array_map(
            fn ($i) => ['create', $package->id, $start->toIso8601String(), 'web', "s{$i}"],
            range(1, 6),
        ));

        $this->assertCount(2, array_filter($results, fn ($r) => $r['ok'] ?? false), json_encode($results));
        $this->assertSame(8, (int) BookingResource::query()->where('is_active', true)->sum('participants'));
    }
}
