<?php

namespace App\Console\Commands;

use App\Modules\Core\Backup\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('aw:backup-restore {file : calea arhivei .zip} {--verify-only} {--force}')]
#[Description('Verifică sau restaurează baza de date dintr-o arhivă aw:backup (înlocuiește datele curente)')]
class BackupRestore extends Command
{
    public function handle(BackupService $backups): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $file = config('aw.backup.path').'/'.$file;
        }
        if (! is_file($file)) {
            $this->error('Arhiva nu există.');

            return self::FAILURE;
        }
        if ($this->option('verify-only')) {
            $check = $backups->verify($file);
            $check['ok'] ? $this->info("Arhivă validă: {$check['tables']} tabele, {$check['rows']} rânduri.") : $this->error(implode("\n", $check['problems']));

            return $check['ok'] ? self::SUCCESS : self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('Restaurarea ÎNLOCUIEȘTE complet baza de date curentă ('.config('database.connections.'.config('database.default').'.database').'). Continuați?')) {
            return self::FAILURE;
        }
        $this->call('down', ['--retry' => 60]);
        try {
            $result = $backups->restore($file);
            $this->info("Restaurare completă: {$result['statements']} instrucțiuni, {$result['rows']} rânduri.");
        } finally {
            $this->call('up');
        }

        return self::SUCCESS;
    }
}
