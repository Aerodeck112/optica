<?php

namespace App\Domain\Cms;

use App\Domain\Scheduling\AvailabilityService;
use App\Models\Location;
use App\Models\Post;
use App\Models\Service;
use App\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Published, public records for the site. Only published_content is read; drafts and
 * internal fields never reach the public pages (ARC-04).
 */
class SiteCatalog
{
    public function services(): Collection
    {
        return Service::query()->with('offerings')->where('is_active', true)->where('is_public', true)
            ->whereNotNull('published_at')->orderBy('sort_order')->get();
    }

    public function specialists(): Collection
    {
        return Specialist::query()->with(['locations' => fn ($q) => $q->where('location_specialist.is_active', true)])
            ->where('is_active', true)->where('is_public', true)->whereNotNull('published_at')
            ->orderBy('sort_order')->orderBy('display_name')->get();
    }

    public function locations(): Collection
    {
        return Location::query()->where('is_active', true)->where('is_public', true)
            ->whereNotNull('published_at')->orderBy('sort_order')->get();
    }

    public function offers(): Collection
    {
        return Post::query()->with('image')->where('type', 'offer')->visible()->orderByDesc('starts_on')->get();
    }

    /**
     * Earliest free online slot per location (WEB-03), cached for 5 minutes.
     *
     * @return array<int, CarbonImmutable|null>
     */
    public function firstAvailableByLocation(): array
    {
        $bucket = intdiv(time(), 300);

        return Cache::remember('site.first_available.'.$bucket, 300, function () {
            $availability = app(AvailabilityService::class);
            $now = CarbonImmutable::now();
            $result = [];
            foreach ($availability->onlineOfferings() as $offering) {
                $slot = $availability->slots($offering, $now, $now->addDays(13), true)[0] ?? null;
                if ($slot && (! isset($result[$offering->location_id]) || $slot['starts_at'] < $result[$offering->location_id])) {
                    $result[$offering->location_id] = $slot['starts_at'];
                }
            }

            return $result;
        });
    }
}
