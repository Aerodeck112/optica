<?php

namespace Tests\Feature\Site;

use App\Domain\Cms\SiteCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SchedulingFixtures;
use Tests\TestCase;

class SiteCatalogCacheTest extends TestCase
{
    use RefreshDatabase, SchedulingFixtures;

    /** The production cache stores no PHP objects; the cached first slot must survive a round trip. */
    public function test_first_available_slot_is_read_back_from_a_serializing_cache(): void
    {
        config(['cache.default' => 'database']);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00', 'Europe/Bucharest'));
        $location = $this->makeLocation();
        $specialist = $this->makeSpecialist();
        $this->makeOffering($location, $specialist, $this->makeService());
        $this->makeSchedule($location, $specialist, [1 => [['09:00', '11:00']]]);

        $first = app(SiteCatalog::class)->firstAvailableByLocation();
        $cached = app(SiteCatalog::class)->firstAvailableByLocation();

        $this->assertInstanceOf(CarbonImmutable::class, $cached[$location->id]);
        $this->assertTrue($first[$location->id]->equalTo($cached[$location->id]));
        $this->assertSame('09:00', $cached[$location->id]->setTimezone('Europe/Bucharest')->format('H:i'));
    }
}
