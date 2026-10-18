<?php

namespace App\Domain\System;

use App\Domain\Messaging\ChannelRegistry;
use App\Support\Install\InstallState;
use App\Support\Recovery\RecoveryKit;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Diagnostics panel (OPS-02): cron, queue, email and channels, errors, disk, backups, updates
 * and configuration. Each check states what was measured; nothing here shows secrets, stack
 * traces or personal data (error lines are reduced to their time and exception class).
 */
class Diagnostics
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    public const INFO = 'info';

    public function __construct(private readonly RecoveryKit $kit) {}

    public static function make(): self
    {
        return new self(new RecoveryKit(base_path()));
    }

    /** @return list<array{group: string, key: string, status: string, detail: string, link?: string}> */
    public function checks(): array
    {
        $checks = [];
        $add = function (string $group, string $key, string $status, string $detail = '', ?string $link = null) use (&$checks) {
            $checks[] = ['group' => $group, 'key' => $key, 'status' => $status, 'detail' => $detail] + ($link ? ['link' => $link] : []);
        };
        $tz = config('optica.clinic_timezone');
        $fmt = fn (?CarbonImmutable $at) => $at ? $at->setTimezone($tz)->format('d.m.Y H:i') : '—';

        // Application
        $add('app', 'version', self::INFO, config('optica.version').' · PHP '.PHP_VERSION.' · '.$this->databaseVersion());
        $production = app()->environment('production');
        $add('app', 'debug', config('app.debug') && $production ? self::ERROR : (config('app.debug') ? self::WARNING : self::OK), config('app.debug') ? 'APP_DEBUG=true' : 'APP_DEBUG=false');
        $add('app', 'https', str_starts_with((string) config('app.url'), 'https://') ? self::OK : self::WARNING, (string) config('app.url'));
        $pending = $this->pendingMigrations();
        $add('app', 'migrations', $pending ? self::ERROR : self::OK, $pending ? implode(', ', array_slice($pending, 0, 3)) : '');
        $add('app', 'maintenance', $this->kit->inMaintenance() ? self::WARNING : self::OK);
        if (is_file(InstallState::tokenPath())) {
            $add('app', 'install_token', self::WARNING, 'storage/app/install-token.txt');
        }

        // Cron and queue
        $cron = Settings::get('system.cron_last_run');
        $cronAt = $cron ? CarbonImmutable::parse($cron) : null;
        $add('jobs', 'cron', ! $cronAt ? self::ERROR : ($cronAt->lt(now()->subMinutes(5)) ? self::ERROR : self::OK), $fmt($cronAt));
        $pendingJobs = DB::table('jobs')->count();
        $oldest = DB::table('jobs')->min('created_at');
        $stale = $oldest && CarbonImmutable::createFromTimestamp((int) $oldest)->lt(now()->subMinutes(15));
        $add('jobs', 'queue', $stale ? self::WARNING : self::OK, (string) $pendingJobs);
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7))->count();
        $add('jobs', 'failed_jobs', $failed ? self::WARNING : self::OK, (string) $failed);

        // Communication
        $mailer = (string) config('mail.default');
        $add('communication', 'mail', in_array($mailer, ['log', 'array'], true) ? self::WARNING : self::OK, $mailer === 'smtp' ? (string) config('mail.mailers.smtp.host') : $mailer);
        foreach (app(ChannelRegistry::class)->overview() as $channel => $state) {
            $add('communication', 'channel_'.$channel, $state === 'ready' ? self::OK : ($state === 'missing' ? self::INFO : self::WARNING), $state);
        }
        $failedMessages = DB::table('messages')->whereIn('status', ['failed', 'uncertain'])->where('created_at', '>=', now()->subDays(7))->count();
        $add('communication', 'messages', $failedMessages ? self::WARNING : self::OK, (string) $failedMessages, route('crm.messages.index'));

        // Errors in the application log
        [$count, $recent] = $this->recentErrors();
        $add('errors', 'log', $count ? self::WARNING : self::OK, $count ? $count.' · '.implode(' · ', $recent) : '0');

        // Storage and backups
        $free = @disk_free_space(base_path());
        $add('storage', 'disk', $free !== false && $free < 1024 ** 3 ? self::WARNING : self::OK, $free === false ? '?' : round($free / 1024 ** 3, 1).' GB');
        $last = Settings::get('system.last_backup');
        $lastAt = $last ? CarbonImmutable::parse($last['at']) : null;
        $schedule = Settings::get('system.backup_schedule', ['enabled' => false]);
        $add('storage', 'backup', ! $lastAt ? self::WARNING : ($lastAt->lt(now()->subHours(26)) && ! empty($schedule['enabled']) ? self::WARNING : self::OK), $fmt($lastAt), route('admin.system.backups'));
        if ($error = Settings::get('system.last_backup_error')) {
            $add('storage', 'backup_error', self::ERROR, $fmt(CarbonImmutable::parse($error['at'])));
        }
        $add('storage', 'backup_schedule', empty($schedule['enabled']) ? self::WARNING : self::OK, empty($schedule['enabled']) ? '' : (string) ($schedule['time'] ?? ''));

        // Updates and recovery
        $add('updates', 'update', $this->kit->interrupted() ? self::ERROR : self::OK, (string) ($this->kit->journal()['status'] ?? ''), route('admin.system.updates'));
        $add('updates', 'keys', UpdatePackage::trustedKeys() ? self::OK : self::WARNING, (string) count(UpdatePackage::trustedKeys()));
        $add('updates', 'recovery', is_file($this->kit->recoveryHashPath()) ? self::OK : self::WARNING);

        return $checks;
    }

    private function databaseVersion(): string
    {
        try {
            return (string) DB::selectOne('SELECT VERSION() AS v')->v;
        } catch (Throwable) {
            return '?';
        }
    }

    /** @return list<string> */
    private function pendingMigrations(): array
    {
        $files = array_map(fn ($f) => basename($f, '.php'), glob(database_path('migrations/*.php')) ?: []);

        return array_values(array_diff($files, DB::table('migrations')->pluck('migration')->all()));
    }

    /**
     * ERROR lines of the last 24 hours. Only the time and the exception class are shown: the
     * message itself may contain data that does not belong on a dashboard.
     *
     * @return array{0: int, 1: list<string>}
     */
    public function recentErrors(): array
    {
        $files = glob(storage_path('logs/*.log')) ?: [];
        $since = now()->subDay();
        $count = 0;
        $recent = [];
        foreach ($files as $file) {
            if (filemtime($file) < $since->getTimestamp()) {
                continue;
            }
            $handle = fopen($file, 'r');
            // Only the end of large logs is read.
            if (filesize($file) > 5 * 1024 * 1024) {
                fseek($handle, -5 * 1024 * 1024, SEEK_END);
            }
            while (($line = fgets($handle)) !== false) {
                if (! preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY):/', $line, $m)) {
                    continue;
                }
                if (CarbonImmutable::parse($m[1])->lt($since)) {
                    continue;
                }
                $count++;
                $class = preg_match('/\(([A-Za-z0-9_\\\\]+)\(code: /', $line, $c) ? class_basename($c[1]) : $m[2];
                $recent[] = $m[1].' '.$class;
            }
            fclose($handle);
        }

        sort($recent);

        return [$count, array_map(fn ($r) => substr($r, 11, 5).substr($r, 19), array_slice($recent, -5))];
    }
}
