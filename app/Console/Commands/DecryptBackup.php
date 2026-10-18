<?php

namespace App\Console\Commands;

use App\Support\Recovery\RecoveryKit;
use Illuminate\Console\Command;

/**
 * Decrypts one part of a downloaded backup (database.sql.gz.enc, files.zip.enc) for inspection
 * or a manual restore on another server. Needs the APP_KEY the backup was made with (SEC-06).
 */
class DecryptBackup extends Command
{
    protected $signature = 'optica:backup-decrypt {file : the .enc part} {output : where to write the plain file} {--key= : APP_KEY of the original installation (default: this one)}';

    protected $description = 'Decrypts a part of a backup with the application key';

    public function handle(): int
    {
        $kit = new RecoveryKit(base_path());
        $kit->decryptFile((string) $this->argument('file'), (string) $this->argument('output'), $kit->backupKey($this->option('key') ?: config('app.key')));
        $this->info('Written: '.$this->argument('output'));

        return self::SUCCESS;
    }
}
