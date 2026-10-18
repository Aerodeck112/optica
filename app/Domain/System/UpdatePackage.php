<?php

namespace App\Domain\System;

use App\Support\Recovery\RecoveryKit;
use RuntimeException;
use ZipArchive;

/**
 * An update archive and its checks (UPD-01…03, D-009):
 *   manifest.json  – version, minimum source and PHP versions, changelog RO/HU, SHA-256 of every file
 *   manifest.sig   – detached Ed25519 signature of manifest.json (base64)
 *   files/…        – the application files of the new version
 * The checksum alone proves nothing about the origin: the signature must come from a trusted key.
 */
class UpdatePackage
{
    /** Never written by an update, whatever the package says (UPD-05). */
    public const PROTECTED_PREFIXES = ['.env', 'storage/', 'public/uploads/', 'public/storage', 'bootstrap/cache/', '.git/', 'node_modules/'];

    public array $manifest = [];

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    public function __construct(public readonly string $path) {}

    /** @return list<string> base64 Ed25519 public keys */
    public static function trustedKeys(): array
    {
        $keys = config('optica.updates.public_keys', []);
        $file = resource_path('update-keys.txt');
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
                $line = trim($line);
                if ($line !== '' && $line[0] !== '#') {
                    $keys[] = $line;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /** Reads and checks the package without extracting anything. */
    public function inspect(): bool
    {
        $this->errors = $this->warnings = [];
        $limits = config('optica.updates');
        if (! is_file($this->path) || filesize($this->path) > $limits['max_package_bytes']) {
            return $this->fail('size');
        }
        $zip = new ZipArchive;
        if ($zip->open($this->path, ZipArchive::CHECKCONS) !== true) {
            return $this->fail('not_zip');
        }
        try {
            $manifestJson = $zip->getFromName('manifest.json');
            $signature = $zip->getFromName('manifest.sig');
            if ($manifestJson === false || $signature === false) {
                return $this->fail('no_manifest');
            }
            if (! $this->signatureValid($manifestJson, trim($signature))) {
                return $this->fail('signature');
            }
            $manifest = json_decode($manifestJson, true);
            if (! is_array($manifest) || ! isset($manifest['version'], $manifest['files']) || ! is_array($manifest['files'])) {
                return $this->fail('no_manifest');
            }
            $this->manifest = $manifest;

            // Entries: count, size, paths, symlinks, one-to-one with the manifest.
            if ($zip->numFiles > $limits['max_entries']) {
                return $this->fail('too_many');
            }
            $total = 0;
            $seen = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                $total += $stat['size'];
                if ($total > $limits['max_extracted_bytes']) {
                    return $this->fail('too_large');
                }
                if (str_ends_with($name, '/') || in_array($name, ['manifest.json', 'manifest.sig'], true)) {
                    continue;
                }
                if (! RecoveryKit::safeRelativePath($name) || ! str_starts_with($name, 'files/')) {
                    return $this->fail('unsafe_path', $name);
                }
                $zip->getExternalAttributesIndex($i, $opsys, $attr);
                if ($opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                    return $this->fail('symlink', $name);
                }
                $relative = substr($name, 6);
                if (self::isProtected($relative)) {
                    return $this->fail('protected', $relative);
                }
                if (! isset($manifest['files'][$relative])) {
                    return $this->fail('unlisted', $relative);
                }
                $seen[$relative] = true;
            }
            foreach ($manifest['files'] as $relative => $hash) {
                if (! isset($seen[$relative]) || ! is_string($hash) || strlen($hash) !== 64) {
                    return $this->fail('missing_file', (string) $relative);
                }
            }
            foreach ($manifest['delete'] ?? [] as $relative) {
                if (! is_string($relative) || ! RecoveryKit::safeRelativePath($relative) || self::isProtected($relative)) {
                    return $this->fail('protected', (string) $relative);
                }
            }
        } finally {
            $zip->close();
        }

        return $this->checkCompatibility();
    }

    public static function isProtected(string $relative): bool
    {
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if ($relative === rtrim($prefix, '/') || str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function signatureValid(string $message, string $signatureB64): bool
    {
        $signature = base64_decode($signatureB64, true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        foreach (self::trustedKeys() as $keyB64) {
            $key = base64_decode($keyB64, true);
            if ($key !== false && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && sodium_crypto_sign_verify_detached($signature, $message, $key)) {
                return true;
            }
        }

        return false;
    }

    /** Source and target versions, PHP version and free space (UPD-02, UPD-03). */
    private function checkCompatibility(): bool
    {
        $current = config('optica.version');
        $m = $this->manifest;
        if (version_compare($m['version'], $current, '<=')) {
            return $this->fail('not_newer', $current);
        }
        if (! empty($m['min_from_version']) && version_compare($current, $m['min_from_version'], '<')) {
            return $this->fail('source_too_old', $m['min_from_version']);
        }
        if (! empty($m['max_from_version']) && version_compare($current, $m['max_from_version'], '>')) {
            return $this->fail('source_too_new', $m['max_from_version']);
        }
        if (! empty($m['min_php']) && version_compare(PHP_VERSION, $m['min_php'], '<')) {
            return $this->fail('php', $m['min_php']);
        }
        // Extraction + backup of the replaced files + database dump, with a margin.
        $needed = (int) ($m['size'] ?? filesize($this->path) * 3) * 2 + 100 * 1024 * 1024;
        $free = @disk_free_space(base_path());
        if ($free !== false && $free < $needed) {
            return $this->fail('space', (string) round($needed / 1048576));
        }

        return true;
    }

    private function fail(string $code, string $detail = ''): bool
    {
        $this->errors[] = __('system.updates.errors.'.$code, ['detail' => $detail]);

        return false;
    }

    /**
     * Extracts the files into a private directory, checking each one against the manifest.
     * Writes go only below $target; the paths were validated by inspect().
     */
    public function extractTo(string $target): void
    {
        $zip = new ZipArchive;
        if ($zip->open($this->path) !== true) {
            throw new RuntimeException('Cannot open the package');
        }
        try {
            foreach ($this->manifest['files'] as $relative => $hash) {
                $contents = $zip->getFromName('files/'.$relative);
                if ($contents === false || hash('sha256', $contents) !== $hash) {
                    throw new RuntimeException('Checksum mismatch: '.$relative);
                }
                $file = $target.'/'.$relative;
                if (! is_dir(dirname($file))) {
                    mkdir(dirname($file), 0755, true);
                }
                file_put_contents($file, $contents);
            }
        } finally {
            $zip->close();
        }
    }
}
