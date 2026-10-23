<?php

namespace App\Modules\Booking\Events;

use App\Modules\Booking\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

class BookingCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Booking $booking,
        public readonly ?string $manageToken = null,
    ) {}
}
