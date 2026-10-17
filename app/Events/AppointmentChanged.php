<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after the booking transaction commits. Listeners (notifications, reminders, cache)
 * must reload the appointment and check its current state (PRG-06).
 */
class AppointmentChanged
{
    use Dispatchable;

    /** @param string $change created | rescheduled | cancelled | status_changed | expired */
    public function __construct(
        public readonly int $appointmentId,
        public readonly string $change,
    ) {}
}
