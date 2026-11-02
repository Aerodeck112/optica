<?php

namespace App\Modules\Booking\Services;

use App\Modules\Core\Settings\SettingsRepository;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Data deschiderii oficiale (Setări → Locație). Până atunci site-ul afișează numărătoarea inversă,
 * iar rezervările online încep din ziua deschiderii; recepția poate rezerva oricând.
 */
class VenueOpening
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ScheduleService $schedule,
    ) {}

    /** Data locală (Y-m-d) sau null dacă nu este setată / este invalidă. */
    public function date(): ?string
    {
        $value = trim((string) $this->settings->filled('venue.opening_date'));

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? substr($value, 0, 10) : null;
    }

    /** Momentul deschiderii (UTC): ora de deschidere din program pentru acea zi sau, dacă lipsește, începutul zilei. */
    public function moment(): ?CarbonImmutable
    {
        $date = $this->date();
        if (! $date) {
            return null;
        }

        return $this->schedule->windowFor($date)['open'] ?? CarbonImmutable::parse($date, LocalTime::zone())->startOfDay()->utc();
    }

    /** Ziua deschiderii nu a trecut încă (inclusiv ziua deschiderii). */
    public function isUpcomingOrToday(): bool
    {
        $date = $this->date();

        return $date !== null && LocalTime::now()->toDateString() <= $date;
    }

    /** Prima zi în care se poate rezerva online: azi sau ziua deschiderii, care e mai târziu. */
    public function firstBookableDate(): string
    {
        $today = LocalTime::now()->toDateString();
        $date = $this->date();

        return $date !== null && $date > $today ? $date : $today;
    }

    public function formatted(): ?string
    {
        $date = $this->date();
        if (! $date) {
            return null;
        }
        $months = [1 => 'ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
        $days = [1 => 'luni', 'marți', 'miercuri', 'joi', 'vineri', 'sâmbătă', 'duminică'];
        $day = CarbonImmutable::parse($date, LocalTime::zone());

        return $days[$day->isoWeekday()].', '.$day->day.' '.$months[$day->month].' '.$day->year;
    }
}
