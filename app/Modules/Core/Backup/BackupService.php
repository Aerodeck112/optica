<?php

namespace App\Modules\Core\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Copii de siguranță fără utilitare externe (pe găzduirea cPanel, exec/mysqldump sunt adesea dezactivate):
 * baza de date se exportă prin PDO, câte o instrucțiune SQL pe linie, iar arhiva conține un manifest cu
 * numărul de rânduri pe tabel și amprenta fișierului SQL, folosite la verificare și la restaurare.
 */
class BackupService
{
    private const SQL_FILE = 'database.sql';

    private const MANIFEST = 'manifest.json';

    /** @return array{path: string, tables: int, rows: int, bytes: int} */
    public function create(string $type = 'database'): array
    {
        if (! in_array($type, ['database', 'full'], true)) {
            throw new RuntimeException('Tip de backup necunoscut.');
        }
        $dir = (string) config('aw.backup.path');
        File::ensureDirectoryExists($dir, 0700);
        $name = 'aw-'.$type.'-'.now()->format('Ymd-His').'.zip';
        $sqlPath = $dir.'/.'.$name.'.sql';

        $counts = $this->dumpDatabase($sqlPath);

        $zip = new ZipArchive;
        if ($zip->open($dir.'/'.$name, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            @unlink($sqlPath);
            throw new RuntimeException('Arhiva de backup nu a putut fi creată.');
        }
        $password = (string) config('aw.backup.password');
        if ($password !== '') {
            $zip->setPassword($password);
        }
        $add = function (string $file, string $entry) use ($zip, $password) {
            $zip->addFile($file, $entry);
            if ($password !== '') {
                $zip->setEncryptionName($entry, ZipArchive::EM_AES_256);
            }
        };
        $add($sqlPath, self::SQL_FILE);
        if ($type === 'full') {
            foreach ([storage_path('app/private') => 'files/private', storage_path('app/public') => 'files/public'] as $root => $prefix) {
                if (! is_dir($root)) {
                    continue;
                }
                foreach (File::allFiles($root) as $file) {
                    $relative = str_replace('\\', '/', $file->getRelativePathname());
                    if (str_starts_with($relative, 'backups/') || str_starts_with($relative, 'exports/')) {
                        continue;
                    }
                    $add($file->getPathname(), $prefix.'/'.$relative);
                }
            }
        }
        $zip->addFromString(self::MANIFEST, json_encode([
            'app' => 'anotherworldcluj',
            'type' => $type,
            'created_at' => now()->toIso8601String(),
            'database' => DB::connection()->getDatabaseName(),
            'sql_sha256' => hash_file('sha256', $sqlPath),
            'tables' => $counts,
            'encrypted' => $password !== '',
        ], JSON_PRETTY_PRINT));
        $zip->close();
        @unlink($sqlPath);
        @chmod($dir.'/'.$name, 0600);

        $this->copyToRemote($dir.'/'.$name);
        $this->prune($type);

        return ['path' => $dir.'/'.$name, 'tables' => count($counts), 'rows' => array_sum($counts), 'bytes' => filesize($dir.'/'.$name)];
    }

    /** Verifică arhiva: integritatea SQL (amprentă) și numărul de rânduri pe tabel față de manifest. */
    public function verify(string $path): array
    {
        [$manifest, $sql] = $this->read($path);
        $problems = [];
        if (! hash_equals((string) $manifest['sql_sha256'], hash_file('sha256', $sql))) {
            $problems[] = 'Amprenta fișierului SQL nu corespunde manifestului.';
        }
        $found = $this->countInserts($sql);
        foreach ((array) $manifest['tables'] as $table => $rows) {
            if (($found[$table] ?? 0) !== (int) $rows) {
                $problems[] = "Tabelul {$table}: {$rows} rânduri în manifest, ".($found[$table] ?? 0).' în SQL.';
            }
        }
        @unlink($sql);

        return ['ok' => $problems === [], 'problems' => $problems, 'tables' => count((array) $manifest['tables']), 'rows' => array_sum((array) $manifest['tables'])];
    }

    /** Restaurează baza de date din arhivă (înlocuiește complet conținutul bazei curente). */
    public function restore(string $path): array
    {
        $check = $this->verify($path);
        if (! $check['ok']) {
            throw new RuntimeException('Arhiva nu a trecut verificarea: '.implode(' ', $check['problems']));
        }
        [, $sql] = $this->read($path);
        $pdo = DB::connection()->getPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $statements = 0;
        try {
            $handle = fopen($sql, 'r');
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '' || str_starts_with($line, '--')) {
                    continue;
                }
                $pdo->exec($line);
                $statements++;
            }
            fclose($handle);
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            @unlink($sql);
        }

        return ['statements' => $statements] + $check;
    }

    /** @return array<int, array{name: string, bytes: int, at: int}> */
    public function list(): array
    {
        $dir = (string) config('aw.backup.path');
        $files = is_dir($dir) ? glob($dir.'/aw-*.zip') : [];

        return collect($files)->map(fn ($file) => ['name' => basename($file), 'bytes' => filesize($file), 'at' => filemtime($file)])->sortByDesc('at')->values()->all();
    }

    /** @return array<string, int> rânduri exportate pe tabel */
    private function dumpDatabase(string $target): array
    {
        $pdo = DB::connection()->getPdo();
        $out = fopen($target, 'w');
        fwrite($out, '-- Another World Cluj — backup '.now()->toIso8601String()."\n");
        $counts = [];
        $tables = array_map(fn ($row) => array_values((array) $row)[0], DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"));
        sort($tables);
        foreach ($tables as $table) {
            $create = (array) DB::selectOne('SHOW CREATE TABLE `'.$table.'`');
            fwrite($out, 'DROP TABLE IF EXISTS `'.$table.'`;'."\n");
            fwrite($out, preg_replace('/\s*\R\s*/', ' ', (string) ($create['Create Table'] ?? array_values($create)[1])).";\n");
            $counts[$table] = 0;
            $columns = null;
            $batch = [];
            $statement = $pdo->query('SELECT * FROM `'.$table.'`');
            while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
                $columns ??= '(`'.implode('`,`', array_keys($row)).'`)';
                $batch[] = '('.implode(',', array_map(fn ($value) => $value === null ? 'NULL' : (is_int($value) || is_float($value) ? (string) $value : $pdo->quote((string) $value)), $row)).')';
                $counts[$table]++;
                if (count($batch) >= 200) {
                    fwrite($out, 'INSERT INTO `'.$table.'` '.$columns.' VALUES '.implode(',', $batch).";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                fwrite($out, 'INSERT INTO `'.$table.'` '.$columns.' VALUES '.implode(',', $batch).";\n");
            }
        }
        fclose($out);

        return $counts;
    }

    /** @return array{0: array, 1: string} manifestul și calea temporară a fișierului SQL extras */
    private function read(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Arhiva nu poate fi deschisă.');
        }
        $password = (string) config('aw.backup.password');
        if ($password !== '') {
            $zip->setPassword($password);
        }
        $manifest = json_decode((string) $zip->getFromName(self::MANIFEST), true);
        $tmp = tempnam(sys_get_temp_dir(), 'awsql');
        $stream = $zip->getStream(self::SQL_FILE);
        if (! is_array($manifest) || ! $stream) {
            $zip->close();
            throw new RuntimeException('Arhiva nu conține manifestul sau baza de date (sau parola de backup este greșită).');
        }
        $target = fopen($tmp, 'w');
        stream_copy_to_stream($stream, $target);
        fclose($target);
        fclose($stream);
        $zip->close();

        return [$manifest, $tmp];
    }

    /** Numără rândurile din instrucțiunile INSERT (fiecare tuplu începe cu „(” după „VALUES ” sau „),”). */
    private function countInserts(string $sqlPath): array
    {
        $counts = [];
        $handle = fopen($sqlPath, 'r');
        while (($line = fgets($handle)) !== false) {
            if (preg_match('/^DROP TABLE IF EXISTS `([^`]+)`/', $line, $m)) {
                $counts[$m[1]] = 0;
            } elseif (preg_match('/^INSERT INTO `([^`]+)` \(.*?\) VALUES /', $line, $m)) {
                $counts[$m[1]] = ($counts[$m[1]] ?? 0) + $this->tuples(substr($line, strpos($line, ' VALUES ') + 8));
            }
        }
        fclose($handle);

        return $counts;
    }

    /** Numără tuplurile de nivel superior, ignorând parantezele din șirurile între ghilimele. */
    private function tuples(string $values): int
    {
        $depth = 0;
        $count = 0;
        $inString = false;
        $length = strlen($values);
        for ($i = 0; $i < $length; $i++) {
            $char = $values[$i];
            if ($inString) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === "'") {
                    $inString = false;
                }

                continue;
            }
            if ($char === "'") {
                $inString = true;
            } elseif ($char === '(') {
                if ($depth === 0) {
                    $count++;
                }
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }
        }

        return $count;
    }

    private function copyToRemote(string $file): void
    {
        $disk = config('aw.backup.remote_disk');
        if (! $disk) {
            return;
        }
        try {
            $stream = fopen($file, 'r');
            Storage::disk($disk)->writeStream('backups/'.basename($file), $stream);
            is_resource($stream) && fclose($stream);
        } catch (\Throwable $e) {
            report($e); // copia locală rămâne; eroarea apare în jurnal
        }
    }

    private function prune(string $type): void
    {
        $keep = $type === 'full' ? (int) config('aw.backup.keep_full', 4) : (int) config('aw.backup.keep_daily', 14);
        $files = glob(config('aw.backup.path').'/aw-'.$type.'-*.zip') ?: [];
        rsort($files);
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
    }
}
