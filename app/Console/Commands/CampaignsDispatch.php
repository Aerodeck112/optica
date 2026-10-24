<?php

namespace App\Console\Commands;

use App\Modules\Marketing\Services\CampaignService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aw:campaigns-dispatch')]
#[Description('Trimite următorul lot din campaniile programate (în afara orelor de liniște)')]
class CampaignsDispatch extends Command
{
    public function handle(CampaignService $campaigns): int
    {
        $count = $campaigns->dispatchDue();
        if ($count > 0) {
            $this->info("{$count} destinatari procesați.");
        }

        return self::SUCCESS;
    }
}
