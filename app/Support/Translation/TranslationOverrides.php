<?php

namespace App\Support\Translation;

use App\Models\TranslationOverride;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Draft → publish workflow for translation corrections with history (TRD-01…TRD-05).
 */
class TranslationOverrides
{
    private const VERSION_KEY = 'translations:version';

    /** @var array<string, array<string, string>> */
    private array $memo = [];

    private ?bool $tableReady = null;

    /** @return array<string, string> key within the group => published text */
    public function publishedForGroup(string $locale, string $group): array
    {
        $all = $this->published($locale);
        $prefix = $group.'.';
        $result = [];
        foreach ($all as $key => $value) {
            if (str_starts_with($key, $prefix)) {
                $result[substr($key, strlen($prefix))] = $value;
            }
        }

        return $result;
    }

    /** @return array<string, string> full key => published text */
    public function published(string $locale): array
    {
        if (! $this->ready()) {
            return [];
        }
        $cacheKey = 'translations:'.$locale.':v'.$this->version();

        return $this->memo[$cacheKey] ??= Cache::rememberForever($cacheKey, fn () => TranslationOverride::query()
            ->where('locale', $locale)
            ->whereNotNull('published_value')
            ->pluck('published_value', 'key')
            ->all());
    }

    public function defaultText(string $locale, string $key): ?string
    {
        [$group, $item] = array_pad(explode('.', $key, 2), 2, null);
        if ($item === null) {
            return null;
        }
        $defaults = app('translation.loader')->loadDefaults($locale, $group);
        $value = $group === '__json' ? ($defaults[$item] ?? null) : Arr::get($defaults, $item);

        return is_string($value) ? $value : null;
    }

    /** @param  string  $action  draft | import | restore – recorded in the history */
    public function saveDraft(string $locale, string $key, string $value, ?User $user = null, string $action = 'draft'): TranslationOverride
    {
        $this->assertLocale($locale);
        $this->assertPlaceholders($locale, $key, $value);

        return DB::transaction(function () use ($locale, $key, $value, $user, $action) {
            $override = TranslationOverride::firstOrNew(['locale' => $locale, 'key' => $key]);
            $override->fill(['draft_value' => $value, 'is_verified' => false, 'updated_by' => $user?->id])->save();
            $this->revision($locale, $key, $value, $action, $user);

            return $override;
        });
    }

    public function publish(string $locale, string $key, ?User $user = null): TranslationOverride
    {
        return DB::transaction(function () use ($locale, $key, $user) {
            $override = TranslationOverride::where(['locale' => $locale, 'key' => $key])->lockForUpdate()->firstOrFail();
            $this->assertPlaceholders($locale, $key, (string) $override->draft_value);
            $override->fill([
                'published_value' => $override->draft_value,
                'published_at' => now(),
                'is_verified' => true,
                'updated_by' => $user?->id,
            ])->save();
            $this->revision($locale, $key, $override->draft_value, 'publish', $user);
            DB::afterCommit(fn () => $this->bumpVersion());

            return $override;
        });
    }

    /** Drops the correction; the standard text from the release applies again. */
    public function reset(string $locale, string $key, ?User $user = null): void
    {
        DB::transaction(function () use ($locale, $key, $user) {
            TranslationOverride::where(['locale' => $locale, 'key' => $key])->delete();
            $this->revision($locale, $key, null, 'reset', $user);
            DB::afterCommit(fn () => $this->bumpVersion());
        });
    }

    /** Placeholders (:name or {name}) must match the standard text exactly (TRD-05). */
    public function assertPlaceholders(string $locale, string $key, string $value): void
    {
        $default = $this->defaultText($locale, $key) ?? $this->defaultText(config('app.fallback_locale'), $key);
        if ($default === null) {
            return;
        }
        $expected = self::placeholders($default);
        $actual = self::placeholders($value);
        if ($expected !== $actual) {
            throw ValidationException::withMessages([
                'value' => __('translations.placeholders_mismatch', [
                    'expected' => implode(', ', $expected) ?: '—',
                    'actual' => implode(', ', $actual) ?: '—',
                ]),
            ]);
        }
    }

    /** @return list<string> sorted unique placeholders */
    public static function placeholders(string $text): array
    {
        preg_match_all('/(?<![\w:]):[A-Za-z_][A-Za-z0-9_]*|\{[a-z_][a-z0-9_]*\}/', $text, $m);
        $found = array_values(array_unique($m[0]));
        sort($found);

        return $found;
    }

    public function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    private function bumpVersion(): void
    {
        Cache::forever(self::VERSION_KEY, $this->version() + 1);
        $this->memo = [];
        // Lines already loaded in this process must be reloaded.
        app('translator')->setLoaded([]);
    }

    private function revision(string $locale, string $key, ?string $value, string $action, ?User $user): void
    {
        DB::table('translation_revisions')->insert([
            'locale' => $locale,
            'key' => $key,
            'value' => $value,
            'action' => $action,
            'user_id' => $user?->id,
            'created_at' => now(),
        ]);
    }

    private function assertLocale(string $locale): void
    {
        if (! in_array($locale, config('optica.locales'), true)) {
            throw ValidationException::withMessages(['locale' => __('translations.invalid_locale')]);
        }
    }

    /** Before installation the table may not exist yet; the installer must still be translated. */
    private function ready(): bool
    {
        if ($this->tableReady !== null) {
            return $this->tableReady;
        }
        try {
            return $this->tableReady = Schema::hasTable('translation_overrides');
        } catch (Throwable) {
            return $this->tableReady = false;
        }
    }
}
