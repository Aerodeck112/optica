<?php

namespace App\Console\Commands;

use App\Support\Recovery\RecoveryKit;
use Illuminate\Console\Command;

/** New token for public/recovery.php; the previous one stops working (UPD-06). */
class RecoveryToken extends Command
{
    protected $signature = 'optica:recovery-token';

    protected $description = 'Generates a new recovery token (shown once)';

    public function handle(): int
    {
        $this->line((new RecoveryKit(base_path()))->newRecoveryToken());

        return self::SUCCESS;
    }
}
