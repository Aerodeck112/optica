<?php

namespace App\Support\Install;

/**
 * First request after unzipping (INS-01): there is no .env yet, so Laravel has no key for
 * sessions and CSRF. A production .env with a fresh APP_KEY and file-based sessions and cache
 * is written from .env.example; the installer fills in the database and the rest.
 */
class EnvBootstrap
{
    public static function ensure(string $basePath): void
    {
        $env = $basePath.'/.env';
        if (is_file($env) || is_file($basePath.'/storage/app/installed.lock') || getenv('APP_ENV') === 'testing' || ! is_file($basePath.'/.env.example')) {
            return;
        }
        $values = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_URL' => '',
            'LOG_LEVEL' => 'warning',
            'SESSION_DRIVER' => 'file',
            'CACHE_STORE' => 'file',
            'QUEUE_CONNECTION' => 'database',
            'MAIL_MAILER' => 'log',
            'DB_DATABASE' => '',
            'DB_USERNAME' => '',
            'DB_PASSWORD' => '',
        ];
        @file_put_contents($env, self::apply((string) file_get_contents($basePath.'/.env.example'), $values));
        @chmod($env, 0600);
    }

    /** Replaces or appends KEY=value lines, quoting values that need it. */
    public static function apply(string $contents, array $values): string
    {
        foreach ($values as $key => $value) {
            $line = $key.'='.self::quote((string) $value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            $contents = preg_match($pattern, $contents) ? preg_replace_callback($pattern, fn () => $line, $contents) : rtrim($contents)."\n".$line."\n";
        }

        return $contents;
    }

    public static function quote(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/@+\-]+$/', $value)) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }

    public static function write(string $basePath, array $values): void
    {
        $env = $basePath.'/.env';
        file_put_contents($env, self::apply(is_file($env) ? (string) file_get_contents($env) : '', $values));
    }
}
