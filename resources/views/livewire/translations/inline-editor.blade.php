<div data-tr-skip>
    @if ($key)
        <aside class="fixed inset-x-0 bottom-0 z-50 max-h-[85dvh] overflow-y-auto rounded-t-2xl bg-white p-5 shadow-2xl ring-1 ring-line sm:inset-x-auto sm:right-4 sm:bottom-4 sm:w-[28rem] sm:rounded-2xl"
               role="dialog" aria-modal="false" aria-labelledby="tr-title" x-data @keydown.escape.window="$wire.close()">
            <div class="flex items-start gap-3">
                <div class="min-w-0 flex-1">
                    <h2 id="tr-title" class="text-base font-semibold text-heading">{{ __('translations.editor.title') }}</h2>
                    <p class="break-all font-mono text-xs text-muted">{{ $key }}</p>
                </div>
                <button type="button" class="btn-secondary min-h-9 px-3 text-xs" wire:click="close">{{ __('translations.editor.close') }}</button>
            </div>
            @if (count($keys) > 1)
                <label for="tr-key" class="field-label mt-3">{{ __('translations.editor.same_text') }}</label>
                <select id="tr-key" class="input" wire:change="select($event.target.value)">
                    @foreach ($keys as $k)<option value="{{ $k }}" @selected($k === $key)>{{ $k }}</option>@endforeach
                </select>
            @endif
            <div aria-live="polite">
                @if ($notice)<p class="mt-3 rounded-lg bg-ok-bg px-3 py-2 text-sm text-ok" role="status">{{ $notice }}</p>@endif
                @error('values')<p class="mt-3 rounded-lg bg-bad-bg px-3 py-2 text-sm text-bad" role="alert">{{ $message }}</p>@enderror
            </div>
            @if ($placeholders)
                <p class="mt-3 text-xs text-muted">{{ __('translations.editor.placeholders') }} @foreach ($placeholders as $p)<code class="rounded bg-paper px-1 ring-1 ring-line">{{ $p }}</code> @endforeach</p>
            @endif
            <div class="mt-3 space-y-4">
                @foreach (config('optica.locales') as $locale)
                    <div>
                        <label for="tr-{{ $locale }}" class="field-label">{{ __('crm.languages.'.$locale) }}
                            @if ($info[$locale]['draft'] !== null)<span class="badge-warn ml-1">{{ __('translations.editor.draft') }}</span>
                            @elseif ($info[$locale]['published'] !== null)<span class="badge-info ml-1">{{ __('translations.editor.custom') }}</span>
                            @else<span class="badge-neutral ml-1">{{ __('translations.editor.standard') }}</span>@endif
                            @if (($info[$locale]['default'] ?? '') === '')<span class="badge-bad ml-1">{{ __('translations.editor.missing') }}</span>@endif
                        </label>
                        <textarea id="tr-{{ $locale }}" wire:model="values.{{ $locale }}" rows="3" lang="{{ $locale }}" class="input"></textarea>
                        @if ($info[$locale]['published'] !== null || $info[$locale]['draft'] !== null)
                            <p class="mt-1 text-xs text-muted">{{ __('translations.editor.standard_text') }}: <span lang="{{ $locale }}">{{ $info[$locale]['default'] ?? '—' }}</span>
                                @can('translations.publish')<button type="button" class="btn-link ml-1 text-xs" wire:click="revert('{{ $locale }}')" wire:confirm="{{ __('translations.editor.confirm_reset') }}">{{ __('translations.editor.reset') }}</button>@endcan</p>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" class="btn-secondary" wire:click="save">{{ __('translations.editor.save_draft') }}</button>
                @can('translations.publish')<button type="button" class="btn-primary" wire:click="publish">{{ __('translations.editor.publish') }}</button>@endcan
            </div>
            <p class="mt-2 text-xs text-muted">{{ __('translations.editor.shared_hint') }}</p>
            @if ($history->isNotEmpty())
                <details class="mt-3 text-xs">
                    <summary class="cursor-pointer text-ink">{{ __('translations.editor.history') }}</summary>
                    <ol class="mt-2 space-y-1 text-muted" role="list">
                        @foreach ($history as $h)
                            <li>{{ \Carbon\CarbonImmutable::parse($h->created_at)->timezone(config('optica.clinic_timezone'))->format('d.m.Y H:i') }} · {{ strtoupper($h->locale) }} · {{ __('translations.actions.'.$h->action) }} · {{ $h->name ?? '—' }}@if ($h->value) – „<span lang="{{ $h->locale }}">{{ \Illuminate\Support\Str::limit($h->value, 60) }}</span>”
                                <button type="button" class="btn-link text-xs" wire:click="restore({{ $h->id }})">{{ __('translations.editor.restore') }}</button>@endif</li>
                        @endforeach
                    </ol>
                </details>
            @endif
        </aside>
    @endif
</div>
