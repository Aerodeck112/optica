<?php

namespace App\Models\Concerns;

/**
 * Bilingual content stored as JSON: {"ro": {...}, "hu": {...}}.
 * `content` is the editable draft; `published_content` is what the public site reads.
 */
trait HasTranslatedContent
{
    public function contentValue(string $field, ?string $locale = null, bool $published = false): ?string
    {
        $locale ??= app()->getLocale();
        $source = $published ? ($this->published_content ?? []) : ($this->content ?? []);

        $value = $source[$locale][$field] ?? null;
        if ($value === null || $value === '') {
            $value = $source[config('app.fallback_locale')][$field] ?? null;
        }

        return $value;
    }

    public function publicValue(string $field, ?string $locale = null): ?string
    {
        return $this->contentValue($field, $locale, published: true);
    }

    public function publish(): void
    {
        $this->published_content = $this->content;
        $this->published_at = now();
        $this->save();
    }

    public function hasUnpublishedChanges(): bool
    {
        return $this->content != $this->published_content;
    }
}
