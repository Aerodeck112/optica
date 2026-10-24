<?php

namespace App\Console\Commands;

use App\Modules\Core\Backup\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aw:backup {--type=database : database | full (bază + fișiere)} {--no-verify}')]
#[Description('Creează o copie de siguranță (și o verifică imediat)')]
class Backup extends Command
{
    public function handle(BackupService $backups): int
    {
        $result = $backups->create((string) $this->option('type'));
        $this->info(sprintf('Backup creat: %s (%d tabele, %d rânduri, %.1f MB)', $result['path'], $result['tables'], $result['rows'], $result['bytes'] / 1048576));
        if (! $this->option('no-verify')) {
            $check = $backups->verify($result['path']);
            if (! $check['ok']) {
                $this->error('Verificarea a eșuat: '.implode(' ', $check['problems']));

                return self::FAILURE;
            }
            $this->info('Verificare OK: SQL complet, rândurile corespund manifestului.');
        }

        return self::SUCCESS;
    }
}
