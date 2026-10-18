<?php

namespace App\Support\Translation;

use Illuminate\Translation\Translator;

/**
 * Standard translator that reports each displayed string to the recorder while translation mode
 * is on. Off, it behaves exactly like Laravel's translator.
 */
class RecordingTranslator extends Translator
{
    /** Inner lookups (hasForLocale, the raw template) call get() again and must not record. */
    private bool $checking = false;

    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        $line = parent::get($key, $replace, $locale, $fallback);
        if ($this->checking) {
            return $line;
        }

        $recorder = app(TranslationRecorder::class);
        if ($recorder->enabled() && is_string($line) && is_string($key) && $line !== $key) {
            $locale ??= $this->locale;
            // With variables, only the template is kept: names and other data never enter the dictionary.
            $text = $replace ? $this->withoutRecording(fn () => parent::get($key, [], $locale, $fallback)) : $line;
            $pattern = $replace !== [] && is_string($text) && $text !== $line;
            $json = $this->loaded['*']['*'][$locale][$key] ?? null;
            if ($json !== null) {
                $recorder->record('__json.'.$key, (string) $text, false, $pattern);
            } elseif (str_contains($key, '.') && ! str_contains($key, '::') && is_string($text)) {
                $missing = $this->withoutRecording(fn () => ! $this->hasForLocale($key, $locale));
                $recorder->record($key, $text, $missing, $pattern);
            }
        }

        return $line;
    }

    private function withoutRecording(callable $callback): mixed
    {
        $this->checking = true;
        try {
            return $callback();
        } finally {
            $this->checking = false;
        }
    }
}
