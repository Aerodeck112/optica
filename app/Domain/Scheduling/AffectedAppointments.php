<?php

namespace App\Domain\Scheduling;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Offering;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * After a schedule or exception change, lists future appointments that no longer fit the working
 * time. They are never moved or deleted automatically; staff resolve them one by one (PRG-04).
 */
class AffectedAppointments
{
    public function __construct(private readonly WorkingWindows $windows) {}

    /**
     * @return Collection<int, Appointment>
     */
    public function find(?int $specialistId = null, ?int $locationId = null, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Collection
    {
        $from ??= CarbonImmutable::now();

        $appointments = Appointment::query()
            ->with(['offering.service', 'patient', 'service', 'location', 'specialist'])
            ->whereIn('status', [AppointmentStatus::Pending->value, AppointmentStatus::Confirmed->value])
            ->where('starts_at', '>=', $from->utc())
            ->when($to, fn ($q) => $q->where('starts_at', '<', $to->utc()))
            ->when($specialistId, fn ($q) => $q->where('specialist_id', $specialistId))
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->orderBy('starts_at')
            ->limit(500)
            ->get();

        // Windows are computed once per offering and local date.
        $cache = [];

        return $appointments->filter(function (Appointment $appointment) use (&$cache) {
            $offering = $appointment->offering ?? Offering::firstWhere([
                'location_id' => $appointment->location_id,
                'specialist_id' => $appointment->specialist_id,
                'service_id' => $appointment->service_id,
            ]);
            if ($offering === null) {
                return true;
            }
            $localDate = CarbonImmutable::instance($appointment->starts_at)->setTimezone($this->windows->timezone());
            $key = $offering->id.'|'.$localDate->toDateString();
            $cache[$key] ??= $this->windows->forDate($offering, $localDate);

            $interval = new Interval(CarbonImmutable::instance($appointment->starts_at), CarbonImmutable::instance($appointment->ends_at));
            foreach ($cache[$key] as $window) {
                if ($window->contains($interval)) {
                    return false;
                }
            }

            return true;
        })->values();
    }
}
