<?php

namespace App\Support\Translation;

use Illuminate\Support\Arr;
use Illuminate\Translation\FileLoader;

/**
 * Standard lines come from lang/{locale}/*.php (replaced by ZIP updates); the customer's
 * published corrections from the database are applied on top and survive updates (D-008, TRD-04).
 */
class OverrideFileLoader extends FileLoader
{
    public function load($locale, $group, $namespace = null)
    {
        $lines = parent::load($locale, $group, $namespace);

        // JSON lines (texts used as keys, e.g. the password email) are stored as "__json.<text>".
        if ($group === '*' && ($namespace === '*' || $namespace === null)) {
            foreach (app(TranslationOverrides::class)->publishedForGroup($locale, '__json') as $text => $value) {
                $lines[$text] = $value;
            }

            return $lines;
        }
        if ($namespace !== null && $namespace !== '*') {
            return $lines;
        }

        foreach (app(TranslationOverrides::class)->publishedForGroup($locale, $group) as $key => $value) {
            Arr::set($lines, $key, $value);
        }

        return $lines;
    }

    /** Lines without database corrections: the reference for placeholder validation and "reset". */
    public function loadDefaults(string $locale, string $group): array
    {
        return $group === '__json' ? parent::load($locale, '*', '*') : parent::load($locale, $group);
    }
}
