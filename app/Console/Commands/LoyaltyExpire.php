<?php

namespace App\Console\Commands;

use App\Modules\Loyalty\Services\LoyaltyService;
use App\Modules\Loyalty\Services\VoucherService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aw:loyalty-expire')]
#[Description('Expiră punctele de fidelitate (FIFO) și voucherele cu data depășită')]
class LoyaltyExpire extends Command
{
    public function handle(LoyaltyService $loyalty, VoucherService $vouchers): int
    {
        $customers = $loyalty->expireAll();
        $expiredVouchers = $vouchers->expireDue();
        $this->info("Puncte expirate pentru {$customers} clienți; {$expiredVouchers} vouchere expirate.");

        return self::SUCCESS;
    }
}
