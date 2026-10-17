<?php

namespace App\Console\Commands;

use App\Domain\Scheduling\BookingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookings:expire')]
#[Description('Release temporary holds and pending online requests whose time ran out')]
class ExpireBookings extends Command
{
    public function handle(BookingService $booking): int
    {
        $count = $booking->expireStale();
        $this->info("Expired: {$count}");

        return self::SUCCESS;
    }
}
