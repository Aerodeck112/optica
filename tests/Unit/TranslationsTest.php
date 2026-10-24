<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class TranslationsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function keys(array $lines, string $prefix = ''): array
    {
        $keys = [];
        foreach ($lines as $key => $value) {
            // listele indexate (ex. zilele săptămânii) se compară ca un singur element
            if (is_array($value) && ! array_is_list($value)) {
                $keys = array_merge($keys, $this->keys($value, $prefix.$key.'.'));
            } else {
                $keys[] = $prefix.$key;
            }
        }

        return $keys;
    }

    public function test_site_translations_have_exactly_the_romanian_keys(): void
    {
        $base = __DIR__.'/../../lang/';
        $romanian = $this->keys(require $base.'ro/site.php');
        foreach (['en', 'hu'] as $locale) {
            $translated = $this->keys(require $base.$locale.'/site.php');
            $this->assertSame([], array_values(array_diff($romanian, $translated)), "Chei lipsă în {$locale}");
            $this->assertSame([], array_values(array_diff($translated, $romanian)), "Chei în plus în {$locale}");
        }
    }

    public function test_placeholders_are_kept_in_every_translation(): void
    {
        $base = __DIR__.'/../../lang/';
        $romanian = require $base.'ro/site.php';
        foreach (['en', 'hu'] as $locale) {
            $translated = require $base.$locale.'/site.php';
            foreach ($this->keys($romanian) as $key) {
                $source = data_get($romanian, $key);
                $target = data_get($translated, $key);
                if (! is_string($source)) {
                    continue;
                }
                preg_match_all('/:[a-z_]+/', $source, $expected);
                preg_match_all('/:[a-z_]+/', (string) $target, $actual);
                sort($expected[0]);
                sort($actual[0]);
                $this->assertSame($expected[0], $actual[0], "Variabile diferite în {$locale}: {$key}");
            }
        }
    }
}
