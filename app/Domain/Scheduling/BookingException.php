<?php

namespace App\Domain\Scheduling;

use RuntimeException;

/**
 * Domain error with a stable code; the message is translated (LNG-03).
 * Messages never reveal other patients' data (PRG-05).
 */
class BookingException extends RuntimeException
{
    public const SLOT_UNAVAILABLE = 'slot_unavailable';

    public const OUTSIDE_SCHEDULE = 'outside_schedule';

    public const SPECIALIST_BUSY = 'specialist_busy';

    public const SPECIALIST_OTHER_LOCATION = 'specialist_other_location';

    public const CAPACITY_REACHED = 'capacity_reached';

    public const RESOURCE_BUSY = 'resource_busy';

    public const ONLINE_DISABLED = 'online_disabled';

    public const TOO_SOON = 'too_soon';

    public const TOO_FAR = 'too_far';

    public const IN_PAST = 'in_past';

    public const INVALID_TRANSITION = 'invalid_transition';

    public const CONCURRENT_MODIFICATION = 'concurrent_modification';

    public const HOLD_EXPIRED = 'hold_expired';

    public const OFFERING_INACTIVE = 'offering_inactive';

    public function __construct(public readonly string $reason, array $replace = [])
    {
        parent::__construct(__('booking.errors.'.$reason, $replace));
    }
}
