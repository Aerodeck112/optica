<?php

namespace App\Support\Install;

/**
 * Whether the platform is installed (INS-02): the lock file written by the last installer step.
 * While it is missing, every request goes to the installer, which asks for a one-time token.
 */
class InstallState
{
    public static function installed(): bool
    {
        $forced = config('optica.installed');

        return $forced ?? is_file(self::lockPath());
    }

    public static function lockPath(): string
    {
        return storage_path('app/installed.lock');
    }

    public static function tokenPath(): string
    {
        return storage_path('app/install-token.txt');
    }

    /** Created at the first visit; read through the cPanel File Manager and typed in the browser. */
    public static function token(): string
    {
        $path = self::tokenPath();
        if (! is_file($path)) {
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, bin2hex(random_bytes(12))."\n");
            @chmod($path, 0600);
        }

        return trim((string) file_get_contents($path));
    }

    public static function lock(string $version): void
    {
        file_put_contents(self::lockPath(), json_encode(['installed_at' => now()->toIso8601String(), 'version' => $version]));
        @unlink(self::tokenPath());
    }
}
