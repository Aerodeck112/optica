<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Key–value settings from the admin panel, cached per request.
 */
class Settings
{
    private static ?array $all = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        self::$all ??= self::load();

        return array_key_exists($key, self::$all) ? self::$all[$key] : $default;
    }

    public static function put(string $key, mixed $value, ?int $userId = null): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
        self::$all = null;
        Cache::forget('settings.all');
    }

    public static function flush(): void
    {
        self::$all = null;
        Cache::forget('settings.all');
    }

    private static function load(): array
    {
        try {
            if (! Schema::hasTable('settings')) {
                return [];
            }

            return Setting::query()->pluck('value', 'key')->all();
        } catch (Throwable) {
            return [];
        }
    }
}
