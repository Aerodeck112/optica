<?php

namespace App\Support\Translation;

/**
 * In translation mode, remembers which texts the page displayed: key → rendered text, and whether
 * the current language lacked the text (fallback). Nothing is added to the texts themselves, so
 * stored data, emails and exports stay untouched (TRD-01, TRD-02).
 */
class TranslationRecorder
{
    private bool $enabled = false;

    /** @var array<string, array{text: string, fallback: bool, pattern?: bool}> */
    private array $seen = [];

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** @param  bool  $pattern  the text is a template with :variables, matched on the page as a pattern */
    public function record(string $key, string $text, bool $fallback, bool $pattern = false): void
    {
        if ($this->enabled && trim($text) !== '' && ! isset($this->seen[$key]) && count($this->seen) < 3000) {
            $this->seen[$key] = ['text' => $text, 'fallback' => $fallback] + ($pattern ? ['pattern' => true] : []);
        }
    }

    /** @return array<string, array{text: string, fallback: bool, pattern?: bool}> */
    public function seen(): array
    {
        return $this->seen;
    }
}
