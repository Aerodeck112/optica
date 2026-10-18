<?php

namespace App\Http\Controllers\Site;

use App\Domain\Scheduling\AvailabilityService;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public availability API: returns free times only, never appointments or patients (PRG-05).
 */
class AvailabilityController extends Controller
{
    public function __invoke(Request $request, AvailabilityService $availability): JsonResponse
    {
        $data = $request->validate([
            'service' => ['required', 'integer'],
            'location' => ['nullable', 'integer'],
            'specialist' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'min:1', 'max:31'],
        ]);
        $tz = config('optica.clinic_timezone');
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'], $tz) : CarbonImmutable::now($tz)->startOfDay();
        $from = $from->max(CarbonImmutable::now($tz)->startOfDay());
        $to = $from->addDays(($data['days'] ?? 7) - 1);

        $slots = [];
        foreach ($availability->onlineOfferings($data['service'], $data['location'] ?? null, $data['specialist'] ?? null) as $offering) {
            foreach ($availability->slots($offering, $from, $to, true) as $slot) {
                $slots[] = [
                    'start' => $slot['starts_at']->setTimezone($tz)->toIso8601String(),
                    'end' => $slot['ends_at']->setTimezone($tz)->toIso8601String(),
                    'location' => $offering->location_id,
                    'specialist' => $offering->specialist_id,
                ];
            }
        }
        usort($slots, fn ($a, $b) => strcmp($a['start'], $b['start']));

        return response()->json(['data' => $slots])->header('Cache-Control', 'no-store');
    }
}
