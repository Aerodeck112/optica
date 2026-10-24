<?php

namespace App\Modules\Loyalty\Listeners;

use App\Modules\Booking\Events\BookingStatusChanged;
use App\Modules\Loyalty\Services\LoyaltyService;

/** Punctele se acordă o singură dată, când rezervarea este finalizată și achitată integral. */
class AwardPointsOnCompletion
{
    public function __construct(private readonly LoyaltyService $loyalty) {}

    public function handle(BookingStatusChanged $event): void
    {
        if ($event->to === 'completed') {
            $this->loyalty->awardForBooking($event->booking->fresh(['customer.tier']));
        }
    }
}
