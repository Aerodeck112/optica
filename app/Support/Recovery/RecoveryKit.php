<?php

namespace App\Support\Recovery;

use PDO;
use RuntimeException;
use ZipArchive;

/**
 * Backup, restore and update journal primitives with no framework dependency, so that
 * public/recovery.php can use them even when the application itself no longer boots (UPD-06).
 * The application uses the same code for its own backups and rollbacks: one tested path.
 */
final class RecoveryKit
{
    public const DUMP_MARKER = '-- optica-dump-complete';

    public function __construct(public readonly string $basePath) {}

    // ----- paths ------------------------------------------------------------------------------

    public function path(string $relative = ''): string
    {
        return rtrim($this->basePath, '/').($relative === '' ? '' : '/'.ltrim($relative, '/'));
    }

    public function journalPath(): string
    {
        return $this->path('storage/app/private/updates/current.json');
    }

    public function backupsPath(): string
    {
        return $this->path('storage/app/private/backups');
    }

    // ----- environment ------------------------------------------------------------------------

    /** Minimal .env reader (KEY=value, quotes, comments); enough for the database settings. */
    public function env(): array
    {
        $file = $this->path('.env');
        $values = [];
        if (! is_file($file)) {
            return $values;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
                $value = substr($value, 1, -1);
                $value = str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
            }
            $values[$key] = $value;
        }

        return $values;
    }

    public function pdo(?array $env = null): PDO
    {
        $env ??= $this->env();
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $env['DB_DATABASE'] ?? '');

        return new PDO($dsn, $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ]);
    }

    // ----- database ---------------------------------------------------------------------------

    /**
     * Pure PHP dump (no mysqldump/exec on shared hosting). One statement per line, so the restore
     * never has to parse SQL; the last line marks a complete dump.
     *
     * @return array{tables: int, rows: int}
     */
    public function dumpDatabase(PDO $pdo, string $gzPath, array $skipDataFor = []): array
    {
        $gz = gzopen($gzPath, 'wb6');
        if (! $gz) {
            throw new RuntimeException('Cannot write '.$gzPath);
        }
        gzwrite($gz, "-- Optica Optofarm database backup\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
        $tables = $this->tables($pdo);
        $rows = 0;
        foreach ($tables as $table) {
            $create = $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetchAll(PDO::FETCH_NUM)[0][1];
            gzwrite($gz, 'DROP TABLE IF EXISTS `'.$table."`;\n");
            gzwrite($gz, str_replace(["\r", "\n"], ' ', $create).";\n");
            if (in_array($table, $skipDataFor, true)) {
                continue;
            }
            $stmt = $pdo->query('SELECT * FROM `'.$table.'`');
            $batch = [];
            $columns = null;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns ??= '(`'.implode('`,`', array_keys($row)).'`)';
                $batch[] = '('.implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $this->quote($pdo, $v), $row)).')';
                $rows++;
                if (count($batch) >= 200) {
                    gzwrite($gz, 'INSERT INTO `'.$table.'` '.$columns.' VALUES '.implode(',', $batch).";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                gzwrite($gz, 'INSERT INTO `'.$table.'` '.$columns.' VALUES '.implode(',', $batch).";\n");
            }
            $stmt->closeCursor();
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n".self::DUMP_MARKER.' tables='.count($tables).' rows='.$rows."\n");
        gzclose($gz);

        return ['tables' => count($tables), 'rows' => $rows];
    }

    /** Line-safe quoting: newlines are escaped, so a statement never spans lines. */
    private function quote(PDO $pdo, mixed $value): string
    {
        $quoted = $pdo->quote((string) $value);

        return str_replace(["\r", "\n"], ['\\r', '\\n'], $quoted);
    }

    /** @return list<string> */
    public function tables(PDO $pdo): array
    {
        return $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    /** @return array{tables: int, rows: int}|null null when the dump is incomplete or unreadable */
    public function verifyDump(string $gzPath): ?array
    {
        $gz = @gzopen($gzPath, 'rb');
        if (! $gz) {
            return null;
        }
        $last = '';
        while (($line = gzgets($gz, 16 * 1024 * 1024)) !== false) {
            if (trim($line) !== '') {
                $last = $line;
            }
        }
        gzclose($gz);
        if (! str_starts_with($last, self::DUMP_MARKER) || ! preg_match('/tables=(\d+) rows=(\d+)/', $last, $m)) {
            return null;
        }

        return ['tables' => (int) $m[1], 'rows' => (int) $m[2]];
    }

    /**
     * Replaces the database with the dump: tables created after the backup (e.g. by a failed
     * migration) are dropped first, so the schema is exactly the backed-up one.
     */
    public function restoreDatabase(PDO $pdo, string $gzPath): void
    {
        if ($this->verifyDump($gzPath) === null) {
            throw new RuntimeException('The database backup is incomplete; nothing was restored.');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($this->tables($pdo) as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');
        }
        $gz = gzopen($gzPath, 'rb');
        while (($line = gzgets($gz, 64 * 1024 * 1024)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '' || str_starts_with($line, '--')) {
                continue;
            }
            $pdo->exec($line);
        }
        gzclose($gz);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    // ----- files ------------------------------------------------------------------------------

    /**
     * @param  list<string>  $relativePaths  files relative to the base path; missing ones are skipped
     * @return int number of files written
     */
    public function zipFiles(array $relativePaths, string $zipPath, ?string $root = null): int
    {
        $root ??= $this->basePath;
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot write '.$zipPath);
        }
        $count = 0;
        foreach ($relativePaths as $relative) {
            $file = rtrim($root, '/').'/'.$relative;
            if (is_file($file) && ! is_link($file)) {
                $zip->addFile($file, $relative);
                $count++;
            }
        }
        if ($count === 0) {
            $zip->addFromString('.empty', '');
        }
        $zip->close();

        return $count;
    }

    /** Every regular file below a directory, relative to $root, skipping excluded prefixes. */
    public function listFiles(string $dir, string $root, array $excludePrefixes = []): array
    {
        $result = [];
        if (! is_dir($dir)) {
            return $result;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = ltrim(substr($file->getPathname(), strlen(rtrim($root, '/'))), '/');
            foreach ($excludePrefixes as $prefix) {
                if (str_starts_with($relative, $prefix)) {
                    continue 2;
                }
            }
            $result[] = $relative;
        }
        sort($result);

        return $result;
    }

    /** Restores files from a backup archive written by zipFiles(); each file is replaced atomically. */
    public function unzipFiles(string $zipPath, ?string $root = null): int
    {
        $root ??= $this->basePath;
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Cannot open '.$zipPath);
        }
        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === '.empty' || str_ends_with($name, '/')) {
                continue;
            }
            if (! self::safeRelativePath($name)) {
                throw new RuntimeException('Unsafe path in backup: '.$name);
            }
            $this->writeAtomic(rtrim($root, '/').'/'.$name, (string) $zip->getFromIndex($i));
            $count++;
        }
        $zip->close();

        return $count;
    }

    public function writeAtomic(string $target, string $contents): void
    {
        $dir = dirname($target);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Cannot create '.$dir);
        }
        $tmp = $dir.'/.optica-tmp-'.bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $contents) === false || ! rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot write '.$target);
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($target, true);
        }
    }

    /** Relative, no "..", no absolute or drive paths, no backslashes or control characters. */
    public static function safeRelativePath(string $path): bool
    {
        if ($path === '' || $path[0] === '/' || str_contains($path, '\\') || preg_match('/[\x00-\x1f]/', $path) || preg_match('/^[A-Za-z]:/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '..' || $part === '.') {
                return false;
            }
        }

        return true;
    }

    // ----- encryption at rest (SEC-06) -------------------------------------------------------

    private const ENC_MAGIC = 'OPTB1';

    private const ENC_CHUNK = 1048576;

    /**
     * Key for backup files, derived from APP_KEY: whoever can read the clinical data of a backup
     * can also open the backup, and nobody else (downloaded copies stay protected).
     */
    public function backupKey(?string $appKey = null): string
    {
        $appKey ??= (string) ($this->env()['APP_KEY'] ?? '');
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }
        if ($appKey === '') {
            throw new RuntimeException('APP_KEY is missing; backups cannot be encrypted or read.');
        }

        return hash_hkdf('sha256', $appKey, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, 'optica-backup-v1');
    }

    /** Authenticated streaming encryption (XChaCha20-Poly1305); a truncated or altered file never decrypts. */
    public function encryptFile(string $in, string $out, string $key): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        fwrite($dst, self::ENC_MAGIC.$header);
        do {
            $chunk = (string) fread($src, self::ENC_CHUNK);
            $last = feof($src);
            $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            fwrite($dst, pack('N', strlen($cipher)).$cipher);
        } while (! $last);
        fclose($src);
        fclose($dst);
    }

    public function decryptFile(string $in, string $out, string $key): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        try {
            $magic = fread($src, strlen(self::ENC_MAGIC));
            $header = fread($src, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if ($magic !== self::ENC_MAGIC || strlen((string) $header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new RuntimeException('Not an encrypted backup file.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $final = false;
            while (! $final && ($len = fread($src, 4)) !== false && strlen($len) === 4) {
                $cipher = (string) fread($src, unpack('N', $len)[1]);
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if ($result === false) {
                    throw new RuntimeException('The backup file is damaged or the key is wrong.');
                }
                fwrite($dst, $result[0]);
                $final = $result[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            if (! $final) {
                throw new RuntimeException('The backup file is incomplete.');
            }
        } finally {
            fclose($src);
            fclose($dst);
        }
    }

    /**
     * Plain path of a backup part: encrypted parts are decrypted to a temporary file, which the
     * caller passes to forget() when done.
     */
    public function readable(string $path, ?string $appKey = null): string
    {
        if (! str_ends_with($path, '.enc')) {
            return $path;
        }
        $tmp = dirname($path).'/.tmp-'.bin2hex(random_bytes(6)).'-'.basename($path, '.enc');
        try {
            $this->decryptFile($path, $tmp, $this->backupKey($appKey));
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }

        return $tmp;
    }

    public function forget(string $original, string $readable): void
    {
        if ($readable !== $original) {
            @unlink($readable);
        }
    }

    /** The part as written by BackupManager: encrypted when an .enc file exists. */
    public function part(string $dir, string $name): ?string
    {
        foreach ([$dir.'/'.$name.'.enc', $dir.'/'.$name] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    // ----- maintenance and compiled caches ----------------------------------------------------

    public function maintenanceOn(string $secret): void
    {
        $this->writeAtomic($this->path('storage/framework/down'), json_encode([
            'except' => [], 'redirect' => null, 'retry' => 60, 'refresh' => 60, 'secret' => $secret, 'status' => 503, 'template' => null,
        ], JSON_PRETTY_PRINT));
    }

    public function maintenanceOff(): void
    {
        @unlink($this->path('storage/framework/down'));
    }

    public function inMaintenance(): bool
    {
        return is_file($this->path('storage/framework/down'));
    }

    /** Compiled config/routes/services refer to the code that was just replaced. */
    public function clearCompiled(): void
    {
        foreach (glob($this->path('bootstrap/cache/*.php')) ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->path('storage/framework/views/*.php')) ?: [] as $file) {
            @unlink($file);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    // ----- update journal ---------------------------------------------------------------------

    public function journal(): ?array
    {
        $file = $this->journalPath();

        return is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    }

    public function writeJournal(array $journal): void
    {
        $journal['updated_at'] = gmdate('c');
        $this->writeAtomic($this->journalPath(), json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function log(array &$journal, string $message, string $level = 'info'): void
    {
        $journal['log'][] = ['at' => gmdate('c'), 'step' => $journal['step'] ?? null, 'level' => $level, 'message' => $message];
    }

    /** An update that did not finish and did not roll back. */
    public function interrupted(): bool
    {
        $j = $this->journal();

        return $j !== null && in_array($j['status'] ?? '', ['running', 'failed'], true);
    }

    /**
     * Puts back the state before the update: replaced files, removed new files, database (if
     * the update reached the migrations), compiled caches, and leaves maintenance mode.
     */
    public function rollback(?PDO $pdo = null, ?string $appKey = null): array
    {
        $journal = $this->journal();
        if (! $journal || empty($journal['backup_dir'])) {
            throw new RuntimeException('No interrupted update with a backup was found.');
        }
        $dir = $journal['backup_dir'];
        if (! self::safeRelativePath($dir) || ! is_dir($this->path($dir))) {
            throw new RuntimeException('The update backup is missing.');
        }
        $journal['step'] = 'rollback';
        $this->log($journal, 'Rollback started');
        $this->writeJournal($journal);

        if ($files = $this->part($this->path($dir), 'app-files.zip')) {
            $plain = $this->readable($files, $appKey);
            try {
                $this->unzipFiles($plain);
            } finally {
                $this->forget($files, $plain);
            }
        }
        foreach ($journal['new_files'] ?? [] as $relative) {
            if (self::safeRelativePath($relative) && is_file($this->path($relative))) {
                @unlink($this->path($relative));
            }
        }
        if (! empty($journal['db_touched'])) {
            $dump = $this->part($this->path($dir), 'database.sql.gz') ?? throw new RuntimeException('The database backup is missing.');
            $plain = $this->readable($dump, $appKey);
            try {
                $this->restoreDatabase($pdo ?? $this->pdo(), $plain);
            } finally {
                $this->forget($dump, $plain);
            }
            $this->log($journal, 'Database restored from the backup');
        }
        $this->clearCompiled();
        $journal['status'] = 'rolled_back';
        $this->log($journal, 'Rollback finished');
        $this->writeJournal($journal);
        $this->maintenanceOff();

        return $journal;
    }

    // ----- recovery token ---------------------------------------------------------------------

    public function recoveryHashPath(): string
    {
        return $this->path('storage/app/private/recovery.hash');
    }

    public function newRecoveryToken(): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $this->writeAtomic($this->recoveryHashPath(), password_hash($token, PASSWORD_DEFAULT));

        return $token;
    }

    public function checkRecoveryToken(string $token): bool
    {
        $file = $this->recoveryHashPath();

        return $token !== '' && is_file($file) && password_verify($token, trim((string) file_get_contents($file)));
    }
}
