<?php

namespace App\Livewire\Translations;

use App\Models\TranslationOverride;
use App\Support\Translation\TranslationCatalog;
use App\Support\Translation\TranslationOverrides;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Side panel of the in-page translation editor (TRD-01, TRD-02): the selected text in RO and HU,
 * the standard text, draft, preview on the page, publication and history. Changing a key changes
 * every place that uses it.
 */
class InlineEditor extends Component
{
    public array $keys = [];

    public ?string $key = null;

    public array $values = [];

    public ?string $notice = null;

    public function boot(): void
    {
        $this->authorize('translations.edit');
    }

    #[On('tr-open')]
    public function open(array $keys): void
    {
        $this->keys = array_values(array_filter($keys, fn ($k) => is_string($k) && $this->validKey($k)));
        $this->select($this->keys[0] ?? null);
    }

    /** Only keys that exist in the shipped language files can be corrected. */
    private function validKey(string $key): bool
    {
        return app(TranslationCatalog::class)->exists($key);
    }

    public function select(?string $key): void
    {
        $this->key = $key && in_array($key, $this->keys, true) ? $key : null;
        $this->notice = null;
        $this->resetErrorBag();
        if (! $this->key) {
            return;
        }
        $overrides = app(TranslationOverrides::class);
        foreach (config('optica.locales') as $locale) {
            $override = TranslationOverride::where(['locale' => $locale, 'key' => $this->key])->first();
            $this->values[$locale] = (string) ($override?->draft_value ?? $override?->published_value ?? $overrides->defaultText($locale, $this->key) ?? '');
        }
    }

    public function close(): void
    {
        $this->keys = [];
        $this->key = null;
    }

    public function save(TranslationOverrides $overrides, bool $publish = false): void
    {
        abort_unless($this->key, 422);
        $this->resetErrorBag();
        if ($publish) {
            $this->authorize('translations.publish');
        }
        try {
            DB::transaction(function () use ($overrides, $publish) {
                foreach (config('optica.locales') as $locale) {
                    $value = trim((string) ($this->values[$locale] ?? ''));
                    $current = TranslationOverride::where(['locale' => $locale, 'key' => $this->key])->first();
                    $effective = $current?->draft_value ?? $current?->published_value ?? $overrides->defaultText($locale, $this->key);
                    if ($value === '' || ($value === $effective && ! ($publish && $current && $current->draft_value !== $current->published_value))) {
                        continue;
                    }
                    if ($value !== $effective) {
                        $overrides->saveDraft($locale, $this->key, $value, auth()->user());
                    }
                    if ($publish) {
                        $overrides->publish($locale, $this->key, auth()->user());
                    }
                }
            });
        } catch (ValidationException $e) {
            $this->addError('values', collect($e->errors())->flatten()->first());

            return;
        }
        $this->notice = $publish ? __('translations.editor.published') : __('translations.editor.draft_saved');
        $this->dispatch('tr-saved');
        // The page shows the new text at once, where it has no variables.
        $current = trim((string) ($this->values[app()->getLocale()] ?? ''));
        if ($publish && $current !== '' && TranslationOverrides::placeholders($current) === []) {
            $this->dispatch('tr-preview', key: $this->key, text: $current);
        }
    }

    public function publish(TranslationOverrides $overrides): void
    {
        $this->save($overrides, true);
    }

    public function revert(TranslationOverrides $overrides, string $locale): void
    {
        $this->authorize('translations.publish');
        abort_unless($this->key && in_array($locale, config('optica.locales'), true), 422);
        $overrides->reset($locale, $this->key, auth()->user());
        $this->select($this->key);
        $this->notice = __('translations.editor.reset_done');
        $this->dispatch('tr-saved');
    }

    /** An earlier text from the history becomes the draft again; publishing stays a separate step. */
    public function restore(TranslationOverrides $overrides, int $revisionId): void
    {
        abort_unless($this->key, 422);
        $revision = DB::table('translation_revisions')->where('key', $this->key)->whereNotNull('value')->find($revisionId);
        abort_unless($revision, 404);
        try {
            $overrides->saveDraft($revision->locale, $this->key, $revision->value, auth()->user(), 'restore');
        } catch (ValidationException $e) {
            $this->addError('values', collect($e->errors())->flatten()->first());

            return;
        }
        $this->select($this->key);
        $this->notice = __('translations.editor.restored');
        $this->dispatch('tr-saved');
    }

    public function render(TranslationOverrides $overrides)
    {
        $info = [];
        if ($this->key) {
            foreach (config('optica.locales') as $locale) {
                $o = TranslationOverride::where(['locale' => $locale, 'key' => $this->key])->first();
                $info[$locale] = [
                    'default' => $overrides->defaultText($locale, $this->key),
                    'published' => $o?->published_value,
                    'draft' => $o && $o->draft_value !== $o->published_value ? $o->draft_value : null,
                ];
            }
        }

        return view('livewire.translations.inline-editor', [
            'info' => $info,
            'placeholders' => $this->key ? TranslationOverrides::placeholders((string) ($info['ro']['default'] ?? $info['hu']['default'] ?? '')) : [],
            'history' => $this->key ? DB::table('translation_revisions')->leftJoin('users', 'users.id', '=', 'translation_revisions.user_id')
                ->where('key', $this->key)->orderByDesc('translation_revisions.id')->limit(10)->get(['translation_revisions.*', 'users.name']) : collect(),
        ]);
    }
}
