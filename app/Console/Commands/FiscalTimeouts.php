<?php

namespace App\Console\Commands;

use App\Modules\Finance\Services\Fiscal\FiscalReceiptService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aw:fiscal-timeouts {--seconds=}')]
#[Description('Marchează ca „incerte” bonurile preluate de agent fără rezultat (nu le retrimite)')]
class FiscalTimeouts extends Command
{
    public function handle(FiscalReceiptService $receipts): int
    {
        $count = $receipts->markTimeouts($this->option('seconds') !== null ? (int) $this->option('seconds') : null);
        if ($count > 0) {
            $this->warn("{$count} bonuri au devenit incerte și necesită reconciliere manuală.");
        }

        return self::SUCCESS;
    }
}
