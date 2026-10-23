<?php

namespace App\Modules\Booking\Events;

use App\Modules\Booking\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

class BookingRescheduled
{
    use Dispatchable;

    public function __construct(
        public readonly Booking $booking,
        public readonly ?\Carbon\CarbonImmutable $previousStart,
        public readonly bool $notifyCustomer = true,
    ) {}
}
