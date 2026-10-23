<?php

namespace App\Modules\Booking\Events;

use App\Modules\Booking\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

class BookingCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly Booking $booking,
        public readonly bool $notifyCustomer = true,
    ) {}
}
