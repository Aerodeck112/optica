<?php

namespace App\Domain\System;

use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Recovery\RecoveryKit;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Verified backups (UPD-04, OPS-02): the database as a pure PHP dump, plus the files the
 * customer owns (uploaded images, private documents). Each backup carries a manifest with the
 * SHA-256 of its parts and is checked after writing; an unverified backup is never used.
 */
class BackupManager
{
    public const TYPES = ['manual', 'scheduled', 'pre_update', 'pre_restore'];

    /** Customer files: never part of the code, always part of a full backup. */
    private const FILE_ROOTS = ['public/uploads', 'storage/app/private'];

    /** Generated or transient data that would make backups recursive or needlessly large. */
    private const FILE_EXCLUDES = ['storage/app/private/backups/', 'storage/app/private/updates/', 'storage/app/private/exports/', 'storage/app/private/livewire-tmp/', 'storage/app/private/imports/'];

    public function __construct(private readonly RecoveryKit $kit) {}

    public static function make(): self
    {
        return new self(new RecoveryKit(base_path()));
    }

    /**
     * @param  list<string>  $codeFiles  application files to keep as well (before an update)
     * @return array manifest
     */
    public function create(string $type, ?User $user = null, bool $withFiles = true, array $codeFiles = []): array
    {
        abort_unless(in_array($type, self::TYPES, true), 422);
        $name = now()->utc()->format('Ymd-His').'-'.$type.'-'.Str::lower(Str::random(4));
        $dir = $this->kit->backupsPath().'/'.$name;
        if (! is_dir($dir) && ! mkdir($dir, 0750, true)) {
            throw new RuntimeException('Cannot create '.$dir);
        }
        file_put_contents($dir.'/.htaccess', "Require all denied\n");

        $dbInfo = $this->kit->dumpDatabase(DB::connection()->getPdo(), $dir.'/database.sql.gz', ['sessions', 'cache', 'cache_locks', 'jobs']);
        $parts = ['database.sql.gz'];
        if ($withFiles) {
            $files = [];
            foreach (self::FILE_ROOTS as $root) {
                $files = [...$files, ...$this->kit->listFiles($this->kit->path($root), $this->kit->path(), self::FILE_EXCLUDES)];
            }
            $this->kit->zipFiles($files, $dir.'/files.zip');
            $parts[] = 'files.zip';
        }
        if ($codeFiles) {
            $this->kit->zipFiles($codeFiles, $dir.'/app-files.zip');
            $parts[] = 'app-files.zip';
        }

        $manifest = [
            'name' => $name,
            'type' => $type,
            'version' => config('optica.version'),
            'created_at' => now()->utc()->toIso8601String(),
            'created_by' => $user?->id,
            'database' => $dbInfo,
            'parts' => [],
        ];
        foreach ($parts as $part) {
            $manifest['parts'][$part] = ['size' => filesize($dir.'/'.$part), 'sha256' => hash_file('sha256', $dir.'/'.$part)];
        }
        $manifest['verified'] = $this->verifyParts($dir, $manifest);
        file_put_contents($dir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if (! $manifest['verified']) {
            throw new RuntimeException('The backup could not be verified: '.$name);
        }
        Settings::put('system.last_backup', ['name' => $name, 'at' => $manifest['created_at'], 'type' => $type]);
        app(AuditLogger::class)->log('system.backup_created', null, ['name' => $name, 'type' => $type], $user);
        $this->prune();

        return $manifest;
    }

    /** Re-reads every part: checksums, a complete dump, readable archives. */
    public function verify(string $name): bool
    {
        $manifest = $this->manifest($name);

        return $manifest !== null && $this->verifyParts($this->dir($name), $manifest);
    }

    private function verifyParts(string $dir, array $manifest): bool
    {
        foreach ($manifest['parts'] as $part => $info) {
            if (! is_file($dir.'/'.$part) || hash_file('sha256', $dir.'/'.$part) !== $info['sha256']) {
                return false;
            }
            if ($part === 'database.sql.gz') {
                $check = $this->kit->verifyDump($dir.'/'.$part);
                if ($check === null || $check['tables'] !== $manifest['database']['tables']) {
                    return false;
                }
            } else {
                $zip = new ZipArchive;
                if ($zip->open($dir.'/'.$part, ZipArchive::CHECKCONS) !== true) {
                    return false;
                }
                $zip->close();
            }
        }

        return true;
    }

    /** @return list<array> newest first */
    public function all(): array
    {
        $list = [];
        foreach (glob($this->kit->backupsPath().'/*/manifest.json') ?: [] as $file) {
            $m = json_decode((string) file_get_contents($file), true);
            if (is_array($m) && isset($m['name'])) {
                $m['size'] = array_sum(array_column($m['parts'] ?? [], 'size'));
                $list[] = $m;
            }
        }
        usort($list, fn ($a, $b) => strcmp($b['name'], $a['name']));

        return $list;
    }

    public function manifest(string $name): ?array
    {
        if (! preg_match('/^[0-9]{8}-[0-9]{6}-[a-z_]+-[a-z0-9]{4}$/', $name)) {
            return null;
        }
        $file = $this->dir($name).'/manifest.json';

        return is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    }

    public function dir(string $name): string
    {
        return $this->kit->backupsPath().'/'.$name;
    }

    /** One downloadable archive with every part and the manifest. */
    public function archive(string $name): string
    {
        $manifest = $this->manifest($name) ?? throw new RuntimeException('Unknown backup');
        $path = $this->dir($name).'/'.$name.'.zip';
        if (! is_file($path)) {
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            foreach ([...array_keys($manifest['parts']), 'manifest.json'] as $part) {
                $zip->addFile($this->dir($name).'/'.$part, $part);
            }
            $zip->close();
        }

        return $path;
    }

    /**
     * Restores the database and the customer files of a verified backup. A safety backup of
     * the current state is taken first, so the restore itself can be undone (UPD-06).
     */
    public function restore(string $name, User $user): array
    {
        $manifest = $this->manifest($name);
        if (! $manifest || ! $this->verify($name)) {
            throw new RuntimeException('The backup is missing or does not pass verification.');
        }
        $safety = $this->create('pre_restore', $user);
        $secret = Str::random(40);
        $this->kit->maintenanceOn($secret);
        try {
            $this->kit->restoreDatabase(DB::connection()->getPdo(), $this->dir($name).'/database.sql.gz');
            if (isset($manifest['parts']['files.zip'])) {
                $this->kit->unzipFiles($this->dir($name).'/files.zip');
            }
        } finally {
            $this->kit->maintenanceOff();
            Settings::flush();
        }
        app(AuditLogger::class)->log('system.backup_restored', null, ['name' => $name, 'safety_backup' => $safety['name']], $user);

        return $safety;
    }

    public function delete(string $name): void
    {
        $dir = $this->dir($name);
        if ($this->manifest($name) === null) {
            return;
        }
        foreach (glob($dir.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($dir);
    }

    /** Keeps the configured number of scheduled/manual backups and the last 3 of the others. */
    public function prune(): void
    {
        $byGroup = [];
        foreach ($this->all() as $m) {
            $group = in_array($m['type'], ['manual', 'scheduled'], true) ? 'regular' : $m['type'];
            $byGroup[$group][] = $m['name'];
        }
        foreach ($byGroup as $group => $names) {
            $keep = $group === 'regular' ? max(1, (int) config('optica.backups.keep')) : 3;
            foreach (array_slice($names, $keep) as $old) {
                $this->delete($old);
            }
        }
    }
}
