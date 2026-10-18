<?php

namespace App\Domain\System;

use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Recovery\RecoveryKit;
use App\Support\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Applies a verified update package in short, resumable steps (UPD-03…06):
 *   backup → maintenance → apply (in batches) → migrate → finalize → check → done
 * Each step is written to the journal on disk before and after it runs. Any failure after the
 * backup rolls back files and database automatically; if even that fails, public/recovery.php
 * can do it without the application.
 */
class UpdateRunner
{
    public const STEPS = ['backup', 'maintenance', 'apply', 'migrate', 'finalize', 'check', 'done'];

    public function __construct(private readonly RecoveryKit $kit) {}

    public static function make(): self
    {
        return new self(new RecoveryKit(base_path()));
    }

    public function kit(): RecoveryKit
    {
        return $this->kit;
    }

    public function journal(): ?array
    {
        return $this->kit->journal();
    }

    /** A run that is verified, running or failed blocks a new upload. */
    public function busy(): bool
    {
        return in_array($this->journal()['status'] ?? null, ['verified', 'running', 'failed'], true);
    }

    public function currentVersion(): string
    {
        return trim((string) @file_get_contents($this->kit->path('VERSION'))) ?: '0.0.0';
    }

    /**
     * Checks an uploaded package and prepares the plan. Rejected packages are recorded and removed.
     *
     * @return array{ok: bool, errors: list<string>, journal: ?array}
     */
    public function prepare(string $uploadedPath, string $originalName, ?User $user): array
    {
        if ($this->busy()) {
            return ['ok' => false, 'errors' => [__('system.updates.errors.busy')], 'journal' => $this->journal()];
        }
        $uuid = (string) Str::uuid();
        $dir = 'storage/app/private/updates/'.$uuid;
        @mkdir($this->kit->path($dir), 0750, true);
        file_put_contents($this->kit->path('storage/app/private/updates/.htaccess'), "Require all denied\n");
        $packagePath = $this->kit->path($dir.'/package.zip');
        if (! @rename($uploadedPath, $packagePath) && ! @copy($uploadedPath, $packagePath)) {
            throw new RuntimeException('Cannot store the package');
        }

        $package = new UpdatePackage($packagePath);
        $from = $this->currentVersion();
        if (! $package->inspect()) {
            $this->record(['run' => $uuid, 'from_version' => $from, 'to_version' => $package->manifest['version'] ?? null, 'package_name' => $originalName,
                'status' => 'rejected', 'errors' => $package->errors, 'log' => [], 'started_by' => $user?->id]);
            @unlink($packagePath);
            @rmdir($this->kit->path($dir));
            app(AuditLogger::class)->log('system.update_rejected', null, ['package' => $originalName, 'errors' => $package->errors], $user);

            return ['ok' => false, 'errors' => $package->errors, 'journal' => null];
        }

        try {
            $package->extractTo($this->kit->path($dir.'/files'));
        } catch (RuntimeException $e) {
            // A file that does not match the signed manifest: the package was altered.
            $errors = [__('system.updates.errors.checksum', ['detail' => Str::after($e->getMessage(), ': ')])];
            $this->record(['run' => $uuid, 'from_version' => $from, 'to_version' => $package->manifest['version'], 'package_name' => $originalName,
                'status' => 'rejected', 'errors' => $errors, 'log' => [], 'started_by' => $user?->id]);
            $this->cleanup(['dir' => $dir]);
            app(AuditLogger::class)->log('system.update_rejected', null, ['package' => $originalName, 'errors' => $errors], $user);

            return ['ok' => false, 'errors' => $errors, 'journal' => null];
        }
        $manifest = $package->manifest;

        // Only files that differ are copied; files of the old release missing from the new one are removed.
        $apply = [];
        $new = [];
        foreach ($manifest['files'] as $relative => $hash) {
            $current = $this->kit->path($relative);
            if (! is_file($current)) {
                $new[] = $relative;
                $apply[] = $relative;
            } elseif (hash_file('sha256', $current) !== $hash) {
                $apply[] = $relative;
            }
        }
        $delete = $manifest['delete'] ?? [];
        $oldRelease = json_decode((string) @file_get_contents($this->kit->path('release.json')), true);
        foreach (array_keys($oldRelease['files'] ?? []) as $relative) {
            if (! isset($manifest['files'][$relative]) && RecoveryKit::safeRelativePath($relative) && ! UpdatePackage::isProtected($relative)) {
                $delete[] = $relative;
            }
        }
        $delete = array_values(array_filter(array_unique($delete), fn ($r) => is_file($this->kit->path($r))));

        $journal = [
            'run' => $uuid,
            'from_version' => $from,
            'to_version' => $manifest['version'],
            'package_name' => $originalName,
            'changelog' => $manifest['changelog'] ?? [],
            'migration_notes' => $manifest['migration_notes'] ?? [],
            'dir' => $dir,
            'status' => 'verified',
            'step' => null,
            'apply' => $apply,
            'apply_index' => 0,
            'new_planned' => $new,
            'new_files' => [],
            'delete' => $delete,
            'db_touched' => false,
            'backup_dir' => null,
            'started_by' => $user?->id,
            'log' => [],
        ];
        $this->kit->log($journal, sprintf('Package %s verified: %d files to replace or add, %d to remove', $manifest['version'], count($apply), count($delete)));
        $this->kit->writeJournal($journal);
        $this->record($journal);

        return ['ok' => true, 'errors' => [], 'journal' => $journal];
    }

    public function start(?User $user): array
    {
        $journal = $this->journal();
        if (($journal['status'] ?? null) !== 'verified') {
            throw new RuntimeException('No verified package is waiting.');
        }
        $journal['status'] = 'running';
        $journal['step'] = 'backup';
        $journal['secret'] = Str::random(40);
        $journal['started_by'] = $user?->id ?? $journal['started_by'];
        $this->kit->log($journal, 'Update started');
        $this->kit->writeJournal($journal);
        $this->record($journal);
        app(AuditLogger::class)->log('system.update_started', null, ['from' => $journal['from_version'], 'to' => $journal['to_version']], $user);

        return $journal;
    }

    /** Drops a verified package that was not started. */
    public function cancel(?User $user): void
    {
        $journal = $this->journal();
        if (($journal['status'] ?? null) !== 'verified') {
            return;
        }
        $journal['status'] = 'cancelled';
        $this->kit->log($journal, 'Cancelled before start');
        $this->cleanup($journal);
        $this->kit->writeJournal($journal);
        $this->record($journal);
    }

    /**
     * Runs the next step. Safe to call again after an interruption: every step can be repeated.
     */
    public function advance(): array
    {
        $lock = fopen($this->kit->path('storage/app/private/updates/.lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return $this->journal() ?? [];
        }
        try {
            $journal = $this->journal();
            if (($journal['status'] ?? null) !== 'running') {
                return $journal ?? [];
            }
            try {
                $journal = $this->runStep($journal);
            } catch (Throwable $e) {
                $journal = $this->journal();
                $journal['status'] = 'failed';
                $journal['error'] = Str::limit($e->getMessage(), 500);
                $this->kit->log($journal, 'Step failed: '.$journal['error'], 'error');
                $this->kit->writeJournal($journal);
                $journal = $this->rollbackAfterFailure($journal);
            }
            $this->record($journal);

            return $journal;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function runStep(array $journal): array
    {
        switch ($journal['step']) {
            case 'backup':
                $codeFiles = array_values(array_unique([...array_diff($journal['apply'], $journal['new_planned']), ...$journal['delete']]));
                $backup = (new BackupManager($this->kit))->create('pre_update', $journal['started_by'] ? User::find($journal['started_by']) : null, false, $codeFiles);
                $journal['backup_dir'] = 'storage/app/private/backups/'.$backup['name'];
                $this->kit->log($journal, 'Verified backup '.$backup['name'].' ('.$backup['database']['tables'].' tables, '.$backup['database']['rows'].' rows)');
                $journal['step'] = 'maintenance';
                break;

            case 'maintenance':
                // Blocks writes and scheduled jobs; the administrator keeps access with the bypass cookie.
                $this->kit->maintenanceOn($journal['secret']);
                $this->kit->log($journal, 'Maintenance mode on');
                $journal['step'] = 'apply';
                break;

            case 'apply':
                $batch = array_slice($journal['apply'], $journal['apply_index'], max(1, (int) config('optica.updates.apply_batch')));
                // New files are recorded before they are written, so a rollback can remove them.
                $journal['new_files'] = array_values(array_unique([...$journal['new_files'], ...array_intersect($batch, $journal['new_planned'])]));
                $this->kit->writeJournal($journal);
                foreach ($batch as $relative) {
                    $this->kit->writeAtomic($this->kit->path($relative), (string) file_get_contents($this->kit->path($journal['dir'].'/files/'.$relative)));
                }
                $journal['apply_index'] += count($batch);
                if ($journal['apply_index'] >= count($journal['apply'])) {
                    foreach ($journal['delete'] as $relative) {
                        @unlink($this->kit->path($relative));
                    }
                    $this->kit->log($journal, sprintf('Files applied (%d), removed (%d)', count($journal['apply']), count($journal['delete'])));
                    $journal['step'] = 'migrate';
                }
                break;

            case 'migrate':
                $journal['db_touched'] = true;
                $this->kit->writeJournal($journal);
                $this->kit->clearCompiled();
                $code = Artisan::call('migrate', ['--force' => true, '--path' => $this->kit->path('database/migrations'), '--realpath' => true]);
                if ($code !== 0) {
                    throw new RuntimeException('Migrations failed: '.Str::limit(trim(Artisan::output()), 300));
                }
                $this->kit->log($journal, 'Migrations applied');
                $journal['step'] = 'finalize';
                break;

            case 'finalize':
                $this->kit->clearCompiled();
                // New permissions of this version reach the default roles (customisations stay).
                Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
                Settings::flush();
                $journal['step'] = 'check';
                break;

            case 'check':
                $this->check($journal);
                $this->kit->log($journal, 'Checks passed');
                $journal['step'] = 'done';
                break;

            case 'done':
                $this->kit->maintenanceOff();
                $journal['status'] = 'done';
                $this->kit->log($journal, 'Update to '.$journal['to_version'].' finished');
                $this->cleanup($journal);
                app(AuditLogger::class)->log('system.update_finished', null, ['from' => $journal['from_version'], 'to' => $journal['to_version']]);
                break;
        }
        $this->kit->writeJournal($journal);

        return $journal;
    }

    /** Functional checks after the files and migrations (UPD-04). */
    private function check(array $journal): void
    {
        foreach ($journal['apply'] as $relative) {
            $expected = hash_file('sha256', $this->kit->path($journal['dir'].'/files/'.$relative));
            if (! is_file($this->kit->path($relative)) || hash_file('sha256', $this->kit->path($relative)) !== $expected) {
                throw new RuntimeException('File not in place after the update: '.$relative);
            }
        }
        if ($this->currentVersion() !== $journal['to_version']) {
            throw new RuntimeException('The version file does not show '.$journal['to_version']);
        }
        DB::select('SELECT 1');
        $files = array_map(fn ($f) => basename($f, '.php'), glob($this->kit->path('database/migrations/*.php')) ?: []);
        $ran = DB::table('migrations')->pluck('migration')->all();
        $pending = array_diff($files, $ran);
        if ($pending) {
            throw new RuntimeException('Pending migrations: '.implode(', ', array_slice($pending, 0, 5)));
        }
    }

    private function rollbackAfterFailure(array $journal): array
    {
        if (empty($journal['backup_dir'])) {
            // Failed before anything changed.
            $journal['status'] = 'cancelled';
            $this->kit->maintenanceOff();
            $this->cleanup($journal);
            $this->kit->writeJournal($journal);

            return $journal;
        }
        try {
            $journal = $this->kit->rollback(DB::connection()->getPdo(), config('app.key'));
            DB::purge();
            Settings::flush();
            $this->cleanup($journal);
            $this->kit->writeJournal($journal);
            app(AuditLogger::class)->log('system.update_rolled_back', null, ['from' => $journal['from_version'], 'to' => $journal['to_version'], 'error' => $journal['error'] ?? null]);
        } catch (Throwable $e) {
            $journal = $this->journal();
            $journal['status'] = 'failed';
            $this->kit->log($journal, 'Automatic rollback failed: '.Str::limit($e->getMessage(), 300).' – use recovery.php', 'error');
            $this->kit->writeJournal($journal);
        }

        return $journal;
    }

    /** Manual rollback of an interrupted or failed update (from the Updates page or the console). */
    public function rollback(?User $user): array
    {
        $journal = $this->kit->rollback(DB::connection()->getPdo(), config('app.key'));
        DB::purge();
        Settings::flush();
        $this->cleanup($journal);
        $this->kit->writeJournal($journal);
        $this->record($journal);
        app(AuditLogger::class)->log('system.update_rolled_back', null, ['from' => $journal['from_version'], 'to' => $journal['to_version'], 'manual' => true], $user);

        return $journal;
    }

    /** The extracted files and the package are no longer needed; the backup is kept. */
    private function cleanup(array $journal): void
    {
        $dir = $this->kit->path($journal['dir']);
        if (! str_contains($journal['dir'], 'storage/app/private/updates/') || ! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /** Mirrors the journal into update_runs (history). After a database restore the row is written again. */
    private function record(array $journal): void
    {
        try {
            DB::table('update_runs')->updateOrInsert(['uuid' => $journal['run']], [
                'from_version' => $journal['from_version'],
                'to_version' => $journal['to_version'] ?? null,
                'package_name' => Str::limit((string) ($journal['package_name'] ?? ''), 180, ''),
                'status' => $journal['status'],
                'step' => $journal['step'] ?? null,
                'log' => json_encode(array_slice($journal['log'] ?? [], -100), JSON_UNESCAPED_UNICODE),
                'errors' => isset($journal['errors']) ? implode("\n", $journal['errors']) : ($journal['error'] ?? null),
                'backup_name' => isset($journal['backup_dir']) ? basename((string) $journal['backup_dir']) : null,
                'started_by' => $journal['started_by'] ?? null,
                'finished_at' => in_array($journal['status'], ['done', 'rolled_back', 'rejected', 'cancelled'], true) ? now() : null,
                'updated_at' => now(),
                'created_at' => DB::raw('COALESCE(created_at, NOW())'),
            ]);
        } catch (Throwable) {
            // The history is secondary; the journal on disk is authoritative.
        }
    }
}
