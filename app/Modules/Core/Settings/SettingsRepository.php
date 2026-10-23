<?php

namespace App\Modules\Core\Settings;

use App\Modules\Audit\AuditLogger;
use App\Modules\Core\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class SettingsRepository
{
    private const CACHE_KEY = 'aw.settings.v1';

    private ?array $loaded = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        if (array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '') {
            return $all[$key];
        }

        // valoarea implicită din schemă are prioritate; $default este doar rezerva pentru chei fără valoare implicită
        return SettingsSchema::get($key)['default'] ?? $default;
    }

    /** Valoarea doar dacă a fost completată explicit (fără valori implicite) — folosită pentru afișare publică. */
    public function filled(string $key): ?string
    {
        $value = $this->all()[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function bool(string $key): bool
    {
        $value = $this->get($key);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $value = $this->get($key, $default);

        return $value === null || $value === '' ? null : (int) $value;
    }

    public function set(string $key, mixed $value, ?int $userId = null): void
    {
        $definition = SettingsSchema::get($key);
        $secret = (bool) ($definition['secret'] ?? false);
        $stored = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : ($value === null ? null : (string) $value);

        $old = $this->all()[$key] ?? null;

        Setting::query()->updateOrCreate(['key' => $key], [
            'value' => $secret && $stored !== null && $stored !== '' ? Crypt::encryptString($stored) : $stored,
            'is_encrypted' => $secret,
            'updated_by' => $userId,
        ]);

        if ($old !== $value) {
            app(AuditLogger::class)->log('settings.updated', null, [
                'key' => $key,
                'old' => $secret ? '••••' : $old,
                'new' => $secret ? '••••' : $value,
            ]);
        }

        $this->flush();
    }

    public function setMany(array $values, ?int $userId = null): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $userId);
        }
    }

    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        return $this->loaded = Cache::rememberForever(self::CACHE_KEY, function () {
            $values = [];
            foreach (Setting::query()->get() as $row) {
                $raw = $row->value;
                if ($row->is_encrypted && $raw !== null && $raw !== '') {
                    try {
                        $raw = Crypt::decryptString($raw);
                    } catch (\Throwable) {
                        $raw = null;
                    }
                }
                $type = SettingsSchema::get($row->key)['type'] ?? 'string';
                $values[$row->key] = in_array($type, ['json', 'multiselect'], true) && is_string($raw) ? json_decode($raw, true) : $raw;
            }

            return $values;
        });
    }

    public function flush(): void
    {
        $this->loaded = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** Câmpurile marcate obligatorii pentru lansare și încă necompletate. */
    public function missingForLaunch(): array
    {
        $missing = [];
        foreach (SettingsSchema::definitions() as $key => $definition) {
            if (($definition['launch'] ?? false) && $this->get($key) === null) {
                $missing[$key] = $definition['label'];
            }
        }

        return $missing;
    }
}
