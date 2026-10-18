<?php

namespace App\Support\Translation;

use App\Models\TranslationOverride;
use Illuminate\Support\Arr;

/**
 * Every translatable text of the application: the standard RO/HU lines shipped in lang/ and the
 * customer's corrections, with the state the translation centre filters by (TRD-03).
 */
class TranslationCatalog
{
    public const STATUSES = ['missing', 'draft', 'modified'];

    /** @var array<string, array{group: string, default: array<string, ?string>}>|null */
    private ?array $keys = null;

    /** @return array<string, array{group: string, default: array<string, ?string>}> */
    public function keys(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }
        $loader = app('translation.loader');
        $locales = config('optica.locales');
        $groups = [];
        foreach ($locales as $locale) {
            foreach (glob(lang_path($locale.'/*.php')) ?: [] as $file) {
                $groups[basename($file, '.php')] = true;
            }
        }
        $keys = [];
        foreach ([...array_keys($groups), '__json'] as $group) {
            foreach ($locales as $locale) {
                $lines = $loader->loadDefaults($locale, $group);
                $flat = $group === '__json' ? $lines : Arr::dot($lines);
                foreach ($flat as $item => $text) {
                    if (! is_string($text)) {
                        continue;
                    }
                    $key = $group.'.'.$item;
                    $keys[$key] ??= ['group' => $group, 'default' => array_fill_keys($locales, null)];
                    $keys[$key]['default'][$locale] = $text;
                }
            }
        }
        ksort($keys);

        return $this->keys = $keys;
    }

    public function exists(string $key): bool
    {
        return isset($this->keys()[$key]);
    }

    /** @return list<string> */
    public function groups(): array
    {
        return array_values(array_unique(array_column($this->keys(), 'group')));
    }

    /**
     * Rows with the standard text, the correction and the state per language.
     *
     * @param  array{q?: string, group?: string, status?: string, keys?: list<string>|null}  $filters
     * @return list<array{key: string, group: string, locales: array<string, array{default: ?string, published: ?string, draft: ?string, current: string, missing: bool}>, missing: bool, draft: bool, modified: bool}>
     */
    public function rows(array $filters = []): array
    {
        $overrides = [];
        foreach (TranslationOverride::all(['locale', 'key', 'draft_value', 'published_value']) as $o) {
            $overrides[$o->key][$o->locale] = $o;
        }
        $q = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $only = isset($filters['keys']) ? array_flip($filters['keys']) : null;
        $rows = [];
        foreach ($this->keys() as $key => $meta) {
            if (($filters['group'] ?? '') !== '' && $meta['group'] !== $filters['group']) {
                continue;
            }
            if ($only !== null && ! isset($only[$key])) {
                continue;
            }
            $row = ['key' => $key, 'group' => $meta['group'], 'locales' => [], 'missing' => false, 'draft' => false, 'modified' => false];
            foreach ($meta['default'] as $locale => $default) {
                $o = $overrides[$key][$locale] ?? null;
                $draft = $o && $o->draft_value !== null && $o->draft_value !== $o->published_value ? $o->draft_value : null;
                $published = $o?->published_value;
                // Missing: no standard text in this language and no published correction; the fallback language shows.
                $missing = ($default === null || $default === '') && ($published === null || $published === '');
                $row['locales'][$locale] = ['default' => $default, 'published' => $published, 'draft' => $draft,
                    'current' => (string) ($published ?? $default ?? ''), 'missing' => $missing];
                $row['missing'] = $row['missing'] || $missing;
                $row['draft'] = $row['draft'] || $draft !== null;
                $row['modified'] = $row['modified'] || $published !== null;
            }
            $status = $filters['status'] ?? '';
            if ($status !== '' && ! $row[$status]) {
                continue;
            }
            if ($q !== '' && ! $this->matches($row, $q)) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function matches(array $row, string $q): bool
    {
        if (str_contains(mb_strtolower($row['key']), $q)) {
            return true;
        }
        foreach ($row['locales'] as $l) {
            foreach ([$l['default'], $l['published'], $l['draft']] as $text) {
                if ($text !== null && str_contains(mb_strtolower($text), $q)) {
                    return true;
                }
            }
        }

        return false;
    }
}
