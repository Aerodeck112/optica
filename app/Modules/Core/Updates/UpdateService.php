<?php

namespace App\Modules\Core\Updates;

use App\Models\User;
use App\Modules\Audit\AuditLogger;
use App\Modules\Core\Backup\BackupService;
use App\Modules\Core\Models\AppUpdate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * Actualizări încărcate din panou. Un pachet este o arhivă .zip cu:
 *  - aw-update.json: {"format": 1, "version": "1.0.1", "description": "...", "delete": ["app/cale", ...], "migrate": true}
 *  - app/...    fișiere care se copiază în folderul aplicației
 *  - public/... fișiere care se copiază în folderul public (public/ sau public_html, după instalare)
 *
 * Înainte de aplicare: copie a bazei de date și a fișierelor care vor fi înlocuite (revenire cu un clic).
 * Fișierele specifice instalării (.env, storage, index.php din public) nu pot fi atinse de un pachet.
 */
class UpdateService
{
    public const MANIFEST = 'aw-update.json';

    private const MAX_FILES = 20000;

    private const MAX_BYTES = 400 * 1024 * 1024;

    /** Căi pe care un pachet nu le poate scrie sau șterge (relative la destinație). */
    private const PROTECTED = [
        'app' => ['.env', 'storage/', 'bootstrap/cache/', '.git/'],
        'public' => ['index.php', '.aw-public', '.htaccess', 'storage/', 'instalare.php'],
    ];

    public function __construct(
        private readonly BackupService $backups,
        private readonly AuditLogger $audit,
    ) {}

    public function appRoot(): string
    {
        return rtrim((string) (config('aw.updates.app_root') ?: base_path()), '/');
    }

    public function publicRoot(): string
    {
        return rtrim((string) (config('aw.updates.public_root') ?: public_path()), '/');
    }

    public function currentVersion(): string
    {
        return trim((string) @file_get_contents($this->appRoot().'/VERSION')) ?: 'necunoscută';
    }

    /**
     * Citește și validează pachetul, fără a modifica nimic.
     *
     * @return array{version: string, description: string, migrate: bool, files: array<int, array{entry: string, scope: string, path: string, size: int}>, delete: array<int, array{scope: string, path: string}>, sha256: string}
     */
    public function inspect(string $zipPath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Fișierul nu este o arhivă .zip validă.');
        }
        try {
            $manifest = json_decode((string) $zip->getFromName(self::MANIFEST), true);
            if (! is_array($manifest) || ($manifest['format'] ?? null) !== 1 || ! preg_match('/^\d+\.\d+\.\d+$/', (string) ($manifest['version'] ?? ''))) {
                throw new RuntimeException('Arhiva nu este un pachet de actualizare Another World (lipsește sau este invalid aw-update.json).');
            }
            if ($zip->numFiles > self::MAX_FILES) {
                throw new RuntimeException('Pachetul conține prea multe fișiere.');
            }
            $files = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $entry = (string) $stat['name'];
                if ($entry === self::MANIFEST || str_ends_with($entry, '/')) {
                    continue;
                }
                [$scope, $path] = $this->target($entry);
                $total += (int) $stat['size'];
                $files[] = ['entry' => $entry, 'scope' => $scope, 'path' => $path, 'size' => (int) $stat['size']];
            }
            if ($total > self::MAX_BYTES) {
                throw new RuntimeException('Pachetul este prea mare după dezarhivare.');
            }
            $delete = array_map(function ($entry) {
                [$scope, $path] = $this->target((string) $entry);

                return ['scope' => $scope, 'path' => $path];
            }, (array) ($manifest['delete'] ?? []));
            if (! $files && ! $delete) {
                throw new RuntimeException('Pachetul nu conține fișiere.');
            }

            return [
                'version' => (string) $manifest['version'],
                'description' => mb_substr((string) ($manifest['description'] ?? ''), 0, 500),
                'migrate' => (bool) ($manifest['migrate'] ?? true),
                'files' => $files,
                'delete' => $delete,
                'sha256' => hash_file('sha256', $zipPath),
            ];
        } finally {
            $zip->close();
        }
    }

    /** Aplică pachetul. La o eroare în timpul copierii, fișierele se readuc automat la starea anterioară. */
    public function apply(string $zipPath, User $user): AppUpdate
    {
        $package = $this->inspect($zipPath);
        $previous = $this->currentVersion();
        $log = [];

        $database = $this->backups->create('database');
        $log[] = 'Copie de siguranță a bazei de date: '.basename($database['path']);

        $backupDir = storage_path('app/private/updates/'.now()->format('Ymd-His').'-'.$package['version']);
        File::ensureDirectoryExists($backupDir, 0700);
        $created = [];

        $update = AppUpdate::query()->create([
            'version' => $package['version'],
            'previous_version' => $previous,
            'description' => $package['description'] ?: null,
            'status' => 'failed',
            'files_count' => count($package['files']),
            'package_sha256' => $package['sha256'],
            'backup_dir' => $backupDir,
            'database_backup' => basename($database['path']),
            'applied_by' => $user->id,
        ]);

        $zip = new ZipArchive;
        $zip->open($zipPath);
        try {
            foreach ($package['files'] as $file) {
                $target = $this->absolute($file['scope'], $file['path']);
                if (is_file($target)) {
                    $this->copyInto($target, $backupDir.'/'.$file['scope'].'/'.$file['path']);
                } else {
                    $created[] = $file['scope'].'/'.$file['path'];
                }
                $content = $zip->getFromName($file['entry']);
                if ($content === false) {
                    throw new RuntimeException("Nu am putut citi {$file['entry']} din pachet.");
                }
                File::ensureDirectoryExists(dirname($target));
                $temporary = $target.'.aw-new';
                if (file_put_contents($temporary, $content) === false || ! rename($temporary, $target)) {
                    @unlink($temporary);
                    throw new RuntimeException("Nu am putut scrie {$file['scope']}/{$file['path']} (drepturi de scriere?).");
                }
            }
            foreach ($package['delete'] as $item) {
                $target = $this->absolute($item['scope'], $item['path']);
                if (is_file($target)) {
                    $this->copyInto($target, $backupDir.'/'.$item['scope'].'/'.$item['path']);
                    unlink($target);
                }
            }
            if (! collect($package['files'])->contains(fn ($file) => $file['scope'] === 'app' && $file['path'] === 'VERSION')) {
                if (is_file($this->appRoot().'/VERSION')) {
                    $this->copyInto($this->appRoot().'/VERSION', $backupDir.'/app/VERSION');
                } else {
                    $created[] = 'app/VERSION';
                }
                file_put_contents($this->appRoot().'/VERSION', $package['version']."\n");
            }
        } catch (\Throwable $e) {
            $zip->close();
            $this->restoreFiles($backupDir, $created);
            $update->forceFill(['log' => array_merge($log, ['Eroare: '.$e->getMessage(), 'Fișierele au fost readuse la versiunea '.$previous.'.'])])->save();
            $this->audit->log('system.update_failed', $update, ['version' => $package['version'], 'error' => $e->getMessage()]);

            throw new RuntimeException('Actualizarea nu a putut fi aplicată: '.$e->getMessage().' Fișierele au fost readuse la starea anterioară.');
        }
        $zip->close();
        file_put_contents($backupDir.'/created.json', json_encode($created));
        $log[] = count($package['files']).' fișiere actualizate'.($package['delete'] ? ', '.count($package['delete']).' șterse' : '').'.';

        $this->refreshRuntime();
        if ($package['migrate']) {
            $code = Artisan::call('migrate', ['--force' => true]);
            $log[] = 'Baza de date: '.($code === 0 ? 'actualizată' : 'EROARE — '.trim(Artisan::output()));
        }
        $this->rebuildCaches($log);

        $update->forceFill(['status' => 'applied', 'log' => $log])->save();
        $this->audit->log('system.updated', $update, ['from' => $previous, 'to' => $package['version'], 'files' => count($package['files'])]);

        return $update;
    }

    /** Readuce fișierele dinaintea unei actualizări (baza de date rămâne; copia ei este disponibilă pentru restaurare). */
    public function rollback(AppUpdate $update, User $user): AppUpdate
    {
        if ($update->status !== 'applied' || ! $update->backup_dir || ! is_dir($update->backup_dir)) {
            throw new RuntimeException('Pentru această actualizare nu există o copie a fișierelor.');
        }
        if (AppUpdate::query()->where('status', 'applied')->where('id', '>', $update->id)->exists()) {
            throw new RuntimeException('Reveniți mai întâi asupra actualizărilor aplicate după aceasta.');
        }
        $created = json_decode((string) @file_get_contents($update->backup_dir.'/created.json'), true) ?: [];
        $this->restoreFiles($update->backup_dir, $created);
        $this->refreshRuntime();
        $log = (array) $update->log;
        $log[] = 'Revenire la versiunea '.$update->previous_version.' ('.now()->format('d.m.Y H:i').').';
        $this->rebuildCaches($log);
        $update->forceFill(['status' => 'rolled_back', 'log' => $log])->save();
        $this->audit->as('user', $user->id)->log('system.update_rolled_back', $update, ['version' => $update->version]);

        return $update;
    }

    /** @return array{0: string, 1: string} [scope, cale relativă normalizată] */
    private function target(string $entry): array
    {
        $entry = str_replace('\\', '/', $entry);
        if (! preg_match('#^(app|public)/(.+)$#', $entry, $m)) {
            throw new RuntimeException("Fișier în afara zonelor permise: {$entry}");
        }
        [$scope, $path] = [$m[1], $m[2]];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
                throw new RuntimeException("Cale nepermisă în pachet: {$entry}");
            }
        }
        foreach (self::PROTECTED[$scope] as $protected) {
            if ($path === rtrim($protected, '/') || (str_ends_with($protected, '/') && str_starts_with($path, $protected))) {
                throw new RuntimeException("Pachetul încearcă să modifice un fișier protejat al instalării: {$entry}");
            }
        }

        return [$scope, $path];
    }

    private function absolute(string $scope, string $path): string
    {
        return ($scope === 'app' ? $this->appRoot() : $this->publicRoot()).'/'.$path;
    }

    private function copyInto(string $source, string $destination): void
    {
        File::ensureDirectoryExists(dirname($destination), 0700);
        copy($source, $destination);
    }

    /** Copiază înapoi fișierele salvate și șterge fișierele noi adăugate de pachet. */
    private function restoreFiles(string $backupDir, array $created): void
    {
        foreach (['app', 'public'] as $scope) {
            $base = $backupDir.'/'.$scope;
            if (! is_dir($base)) {
                continue;
            }
            foreach (File::allFiles($base, true) as $file) {
                $target = $this->absolute($scope, str_replace('\\', '/', $file->getRelativePathname()));
                File::ensureDirectoryExists(dirname($target));
                copy($file->getPathname(), $target);
            }
        }
        foreach ($created as $relative) {
            [$scope, $path] = explode('/', (string) $relative, 2) + [1 => ''];
            if ($path !== '' && in_array($scope, ['app', 'public'], true)) {
                @unlink($this->absolute($scope, $path));
            }
        }
    }

    private function refreshRuntime(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    private function rebuildCaches(array &$log): void
    {
        if (! config('aw.updates.rebuild_caches', true)) {
            return;
        }
        Artisan::call('optimize:clear');
        $code = Artisan::call('optimize');
        $log[] = 'Cache-uri reconstruite: '.($code === 0 ? 'da' : 'EROARE — '.trim(Artisan::output()));
    }
}
