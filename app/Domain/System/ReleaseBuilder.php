<?php

namespace App\Domain\System;

use RuntimeException;
use ZipArchive;

/**
 * Builds the deliverables of a version (INS-01, UPD-01):
 *   optica-<version>-install.zip – the complete application, vendor/ and compiled assets included
 *   optica-<version>-update.zip  – the same files under files/, with a signed manifest
 * Both contain release.json (SHA-256 of every shipped file), which lets the next update remove
 * files that a version no longer has.
 */
class ReleaseBuilder
{
    /** Top-level entries of the application that are shipped. */
    private const INCLUDE = ['app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'storage', 'vendor',
        'artisan', 'composer.json', 'composer.lock', 'VERSION', '.env.example', '.htaccess', 'README.md', 'DEPLOYMENT.md', 'CHANGELOG.md'];

    /** Never shipped: development files, customer data, local state. */
    private const EXCLUDE_PATTERNS = [
        '#(^|/)\.git(/|$)#', '#(^|/)\.github(/|$)#', '#(^|/)node_modules(/|$)#', '#^public/uploads/#', '#^public/hot$#', '#^public/storage$#',
        '#^bootstrap/cache/.+\.php$#', '#^storage/(?!.*\.gitignore$)#', '#^resources/update-keys\.private#', '#\.DS_Store$#',
    ];

    /** @var array<string, string> relative path => sha256 */
    public array $files = [];

    public function __construct(private readonly string $source) {}

    /** @return array<string, string> */
    public function collect(): array
    {
        $files = [];
        foreach (self::INCLUDE as $entry) {
            $path = $this->source.'/'.$entry;
            if (is_file($path)) {
                $files[$entry] = hash_file('sha256', $path);

                continue;
            }
            if (! is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS));
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $relative = $entry.'/'.substr($file->getPathname(), strlen($path) + 1);
                if ($this->excluded($relative)) {
                    continue;
                }
                $files[$relative] = hash_file('sha256', $file->getPathname());
            }
        }
        if (! isset($files['public/build/manifest.json'])) {
            throw new RuntimeException('public/build is missing: run "npm run build" before packaging.');
        }
        if (! isset($files['vendor/autoload.php'])) {
            throw new RuntimeException('vendor/ is missing: run "composer install --no-dev --optimize-autoloader" before packaging.');
        }
        ksort($files);

        return $this->files = $files;
    }

    /** Placeholders that keep the empty storage folders in the archive. */
    private const SKELETON = ['storage/app/private/.gitignore', 'storage/app/public/.gitignore', 'storage/framework/cache/data/.gitignore',
        'storage/framework/sessions/.gitignore', 'storage/framework/views/.gitignore', 'storage/logs/.gitignore', 'bootstrap/cache/.gitignore'];

    private function addSkeleton(ZipArchive $zip): void
    {
        foreach (self::SKELETON as $placeholder) {
            if (! isset($this->files[$placeholder])) {
                $zip->addFromString($placeholder, "*\n!.gitignore\n");
            }
        }
    }

    private function excluded(string $relative): bool
    {
        foreach (self::EXCLUDE_PATTERNS as $pattern) {
            if (preg_match($pattern, $relative)) {
                return true;
            }
        }

        return false;
    }

    /** True when development packages (PHPUnit, Pint…) would be shipped. */
    public function hasDevPackages(): bool
    {
        return is_dir($this->source.'/vendor/phpunit') || is_dir($this->source.'/vendor/laravel/pint');
    }

    public function version(): string
    {
        return trim((string) file_get_contents($this->source.'/VERSION'));
    }

    private function releaseJson(): string
    {
        return json_encode(['version' => $this->version(), 'files' => $this->files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function buildInstall(string $outDir): string
    {
        $path = $outDir.'/optica-'.$this->version().'-install.zip';
        $zip = $this->open($path);
        foreach (array_keys($this->files) as $relative) {
            $zip->addFile($this->source.'/'.$relative, $relative);
        }
        $this->addSkeleton($zip);
        $zip->addFromString('release.json', $this->releaseJson());
        $zip->close();

        return $path;
    }

    /**
     * @param  string  $secretKeyB64  Ed25519 secret key (base64), read from outside the application
     * @param  array{ro?: string, hu?: string}  $changelog
     */
    public function buildUpdate(string $outDir, string $secretKeyB64, string $minFrom, array $changelog = [], array $migrationNotes = []): string
    {
        $secret = base64_decode(trim($secretKeyB64), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Invalid signing key.');
        }
        $files = array_filter($this->files, fn ($r) => ! UpdatePackage::isProtected($r), ARRAY_FILTER_USE_KEY);
        $releaseJson = $this->releaseJson();
        $files['release.json'] = hash('sha256', $releaseJson);
        ksort($files);
        $size = 0;
        foreach (array_keys($files) as $relative) {
            $size += $relative === 'release.json' ? strlen($releaseJson) : filesize($this->source.'/'.$relative);
        }
        $manifest = [
            'name' => 'Optica Optofarm',
            'version' => $this->version(),
            'min_from_version' => $minFrom,
            'min_php' => '8.3.0',
            'released_at' => gmdate('c'),
            'changelog' => $changelog,
            'migration_notes' => $migrationNotes,
            'size' => $size,
            'files' => $files,
        ];
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $path = $outDir.'/optica-'.$this->version().'-update.zip';
        $zip = $this->open($path);
        $zip->addFromString('manifest.json', $manifestJson);
        $zip->addFromString('manifest.sig', base64_encode(sodium_crypto_sign_detached($manifestJson, $secret)));
        foreach (array_keys($files) as $relative) {
            $relative === 'release.json'
                ? $zip->addFromString('files/release.json', $releaseJson)
                : $zip->addFile($this->source.'/'.$relative, 'files/'.$relative);
        }
        $zip->close();

        return $path;
    }

    private function open(string $path): ZipArchive
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot write '.$path);
        }

        return $zip;
    }

    /** The section of CHANGELOG.md for this version, used as the changelog shown before updating. */
    public function changelogSection(): string
    {
        $text = (string) @file_get_contents($this->source.'/CHANGELOG.md');
        $version = preg_quote($this->version(), '/');
        if (preg_match('/^## \['.$version.'\][^\n]*\n(.*?)(?=^## \[|\z)/ms', $text, $m)) {
            return trim($m[1]);
        }

        return '';
    }
}
