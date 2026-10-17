<?php

namespace Tests\Feature\Scheduling;

use App\Domain\Scheduling\BookingException;
use App\Domain\Scheduling\BookingService;
use App\Enums\AppointmentSource;
use App\Models\Appointment;
use App\Models\Offering;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

/**
 * Real parallel processes against MariaDB/MySQL (T02). Each child opens its own connection
 * and tries to book at the same instant; the row locks must serialize them.
 */
class ConcurrentBookingTest extends TestCase
{
    use DatabaseMigrations, SchedulingFixtures;

    private const TZ = 'Europe/Bucharest';

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('pcntl_fork') || DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Needs pcntl and MySQL/MariaDB.');
        }
        $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00', self::TZ));
    }

    /**
     * @param  list<array{0: Offering, 1: string}>  $attempts  offering + local start time
     * @return list<string> outcome per child: "ok", a BookingException reason, or "error:…"
     */
    private function race(array $attempts): array
    {
        $dir = sys_get_temp_dir().'/optica-race-'.uniqid();
        mkdir($dir);
        $startAt = microtime(true) + 0.5;
        DB::disconnect();

        $pids = [];
        foreach ($attempts as $i => [$offering, $time]) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $result = 'error:unknown';
                try {
                    DB::purge();
                    while (microtime(true) < $startAt) {
                        usleep(1000);
                    }
                    app(BookingService::class)->book($offering, [
                        'starts_at' => CarbonImmutable::parse($time, self::TZ),
                        'source' => AppointmentSource::Online,
                        'contact_name' => 'Pacient Demo '.$i,
                    ]);
                    $result = 'ok';
                } catch (BookingException $e) {
                    $result = $e->reason;
                } catch (\Throwable $e) {
                    $result = 'error:'.get_class($e).':'.$e->getMessage();
                }
                file_put_contents("{$dir}/{$i}", $result);
                // Skip PHPUnit/Laravel shutdown in the child.
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();

        $results = [];
        foreach (array_keys($attempts) as $i) {
            $results[] = @file_get_contents("{$dir}/{$i}") ?: 'error:no-result';
            @unlink("{$dir}/{$i}");
        }
        @rmdir($dir);

        return $results;
    }

    public function test_parallel_requests_for_the_same_slot_create_one_appointment(): void
    {
        $location = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $offering = $this->makeOffering($location, $specialist, $this->makeService(['default_duration' => 30]));
        $this->makeSchedule($location, $specialist, [1 => [['09:00', '12:00']]]);

        $results = $this->race(array_fill(0, 8, [$offering, '2026-11-02 09:00']));

        $this->assertSame(1, count(array_keys($results, 'ok', true)), implode(', ', $results));
        $this->assertSame([], array_filter($results, fn ($r) => str_starts_with($r, 'error:')));
        $this->assertSame(1, Appointment::count());
    }

    public function test_parallel_requests_across_locations_and_durations(): void
    {
        $locationA = $this->makeLocation();
        $locationB = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $short = $this->makeOffering($locationA, $specialist, $this->makeService(['default_duration' => 15]));
        $long = $this->makeOffering($locationB, $specialist, $this->makeService(['default_duration' => 60]));
        $this->makeSchedule($locationA, $specialist, [1 => [['09:00', '12:00']]]);
        $this->makeSchedule($locationB, $specialist, [1 => [['09:00', '12:00']]]);

        $results = $this->race([
            [$short, '2026-11-02 09:30'], [$long, '2026-11-02 09:00'],
            [$short, '2026-11-02 09:45'], [$long, '2026-11-02 09:15'],
            [$short, '2026-11-02 09:30'], [$long, '2026-11-02 09:00'],
        ]);

        $this->assertSame([], array_filter($results, fn ($r) => str_starts_with($r, 'error:')), implode(', ', $results));
        $this->assertGreaterThanOrEqual(1, count(array_keys($results, 'ok', true)));

        // Whatever won, no two appointments of the specialist overlap (incl. 30 min default travel).
        $appointments = Appointment::orderBy('block_starts_at')->get();
        for ($i = 1; $i < $appointments->count(); $i++) {
            $prev = $appointments[$i - 1];
            $gap = $prev->location_id === $appointments[$i]->location_id ? 0 : 30;
            $this->assertLessThanOrEqual(
                $appointments[$i]->block_starts_at->getTimestamp(),
                $prev->block_ends_at->getTimestamp() + $gap * 60,
            );
        }
    }

    public function test_parallel_requests_respect_capacity(): void
    {
        $location = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $offering = $this->makeOffering($location, $specialist, $this->makeService(['default_duration' => 30]), ['capacity' => 2]);
        $this->makeSchedule($location, $specialist, [1 => [['09:00', '12:00']]]);

        $results = $this->race(array_fill(0, 6, [$offering, '2026-11-02 10:00']));

        $this->assertSame(2, count(array_keys($results, 'ok', true)), implode(', ', $results));
        $this->assertSame(2, Appointment::count());
    }
}
