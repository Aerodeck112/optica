<?php

namespace App\Modules\Booking\Events;

use App\Modules\Booking\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

class BookingRescheduled
{
    use Dispatchable;

    public function __construct(
        public readonly Booking $booking,
        public readonly ?CarbonImmutable $previousStart,
        public readonly bool $notifyCustomer = true,
    ) {}
}
