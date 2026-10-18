<?php

namespace App\Console\Commands;

use App\Domain\System\BackupManager;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Verified backup of the database and the customer files (OPS-02). With --scheduled it runs only
 * when the daily backup is enabled in Sistem → Backup and today's has not been made yet.
 */
class RunBackup extends Command
{
    protected $signature = 'optica:backup {--scheduled : only if the daily backup is due}';

    protected $description = 'Creates a verified backup (database + uploaded and private files)';

    public function handle(): int
    {
        if ($this->option('scheduled') && ! $this->due()) {
            return self::SUCCESS;
        }
        try {
            $manifest = BackupManager::make()->create($this->option('scheduled') ? 'scheduled' : 'manual');
        } catch (Throwable $e) {
            Settings::put('system.last_backup_error', ['at' => now()->toIso8601String(), 'message' => mb_substr($e->getMessage(), 0, 300)]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        Settings::put('system.last_backup_error', null);
        $this->info('Backup '.$manifest['name'].' verified ('.$manifest['database']['tables'].' tables, '.$manifest['database']['rows'].' rows).');

        return self::SUCCESS;
    }

    private function due(): bool
    {
        $schedule = Settings::get('system.backup_schedule', ['enabled' => false, 'time' => '02:30']);
        if (empty($schedule['enabled'])) {
            return false;
        }
        $tz = config('optica.clinic_timezone');
        $now = CarbonImmutable::now($tz);
        if ($now->format('H:i') < ($schedule['time'] ?? '02:30')) {
            return false;
        }
        $last = Settings::get('system.last_backup');

        return ! $last || ($last['type'] ?? '') !== 'scheduled' || CarbonImmutable::parse($last['at'])->setTimezone($tz)->toDateString() !== $now->toDateString();
    }
}
