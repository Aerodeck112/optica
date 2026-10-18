<div class="max-w-6xl">
    <div class="flex flex-wrap items-start gap-3">
        <p class="max-w-prose flex-1 text-sm text-muted">{{ __('translations.center.intro') }}</p>
        {{-- On wide screens the header carries the switch; small screens get it here. --}}
        @unless (\App\Http\Middleware\TranslationMode::activeFor(request()))
            <form method="POST" action="{{ route('crm.translation-mode') }}" class="sm:hidden">
                @csrf
                <input type="hidden" name="return_to" value="{{ route('crm.dashboard', absolute: false) }}">
                <button type="submit" class="btn-secondary">{{ __('translations.mode.turn_on') }}</button>
            </form>
        @endunless
    </div>

    <div aria-live="polite">
        @if ($notice)<div class="mt-4 rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ $notice }}</div>@endif
    </div>

    {{-- Counts double as filters. --}}
    <dl class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach (['' => 'total', 'missing' => 'missing', 'draft' => 'draft', 'modified' => 'modified'] as $value => $label)
            <div>
                <button type="button" wire:click="$set('status', '{{ $value }}')"
                        @class(['panel w-full px-4 py-3 text-left', 'ring-2 ring-ink' => $status === $value])
                        aria-pressed="{{ $status === $value ? 'true' : 'false' }}">
                    <dt class="text-xs text-muted">{{ __('translations.center.counts.'.$label) }}</dt>
                    <dd @class(['text-2xl font-semibold tabular-nums', 'text-bad' => $label === 'missing' && $counts['missing'] > 0, 'text-heading' => ! ($label === 'missing' && $counts['missing'] > 0)])>{{ $counts[$label] }}</dd>
                </button>
            </div>
        @endforeach
    </dl>

    <div class="mt-6 flex flex-wrap items-end gap-3">
        <div class="w-full sm:max-w-xs">
            <label for="tc-q" class="field-label">{{ __('translations.center.search') }}</label>
            <input id="tc-q" type="search" wire:model.live.debounce.300ms="q" class="input" placeholder="{{ __('translations.center.search_hint') }}">
        </div>
        <div>
            <label for="tc-group" class="field-label">{{ __('translations.center.module') }}</label>
            <select id="tc-group" wire:model.live="group" class="input">
                <option value="">{{ __('crm.all') }}</option>
                @foreach ($groups as $g)<option value="{{ $g }}">{{ $g === '__json' ? __('translations.center.json_group') : $g }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="tc-screen" class="field-label">{{ __('translations.center.page') }}</label>
            <select id="tc-screen" wire:model.live="screen" class="input">
                <option value="">{{ __('crm.all') }}</option>
                @foreach ($routes as $r)<option value="{{ $r }}">{{ $r }}</option>@endforeach
            </select>
        </div>
        <div class="ml-auto flex flex-wrap gap-2">
            <button type="button" class="btn-secondary" wire:click="export('xlsx')">{{ __('translations.center.export', ['format' => 'XLSX']) }}</button>
            <button type="button" class="btn-secondary" wire:click="export('csv')">{{ __('translations.center.export', ['format' => 'CSV']) }}</button>
            @can('translations.publish')
                @if ($counts['draft'] > 0)
                    <button type="button" class="btn-primary" wire:click="publishDrafts" wire:confirm="{{ __('translations.center.confirm_publish') }}">{{ __('translations.center.publish_drafts') }}</button>
                @endif
            @endcan
        </div>
    </div>
    @if ($routes->isEmpty())
        <p class="mt-2 text-xs text-muted">{{ __('translations.center.page_hint') }}</p>
    @endif

    <div class="panel mt-4 overflow-x-auto">
        <table class="table-base table-stack">
            <thead><tr>
                <th scope="col">{{ __('translations.center.key') }}</th>
                @foreach (config('optica.locales') as $l)<th scope="col">{{ __('crm.languages.'.$l) }}</th>@endforeach
                <th scope="col"><span class="sr-only">{{ __('crm.actions.actions') }}</span></th>
            </tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr wire:key="tr-{{ md5($r['key']) }}">
                    <td class="max-w-[16rem]"><span class="break-all font-mono text-xs text-muted">{{ $r['key'] }}</span></td>
                    @foreach (config('optica.locales') as $l)
                        @php($loc = $r['locales'][$l])
                        <td data-label="{{ __('crm.languages.'.$l) }}" lang="{{ $l }}" class="max-w-[22rem]">
                            @if ($loc['missing'])
                                <span class="badge-bad">{{ __('translations.editor.missing') }}</span>
                                <span class="mt-1 block text-xs text-muted">{{ __('translations.center.fallback_shown') }}</span>
                            @else
                                <span class="block text-sm text-heading">{{ \Illuminate\Support\Str::limit($loc['current'], 160) }}</span>
                            @endif
                            @if ($loc['draft'] !== null)
                                <span class="mt-1 block text-xs"><span class="badge-warn">{{ __('translations.editor.draft') }}</span> {{ \Illuminate\Support\Str::limit($loc['draft'], 120) }}</span>
                            @elseif ($loc['published'] !== null)
                                <span class="badge-info mt-1">{{ __('translations.editor.custom') }}</span>
                            @endif
                        </td>
                    @endforeach
                    <td class="cell-actions whitespace-nowrap text-right">
                        <button type="button" class="btn-link text-sm" wire:click="edit(@js($r['key']))">{{ __('crm.actions.edit') }}</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ count(config('optica.locales')) + 2 }}" class="py-10 text-center text-sm text-muted">{{ __('crm.empty_list') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $rows->links() }}</div>

    <div class="mt-10 grid gap-6 lg:grid-cols-2">
        <section class="panel p-5" aria-labelledby="tc-import">
            <h2 id="tc-import" class="text-base font-semibold text-heading">{{ __('translations.center.import_title') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('translations.center.import_hint') }}</p>
            <form wire:submit="import" class="mt-4 flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1">
                    <label for="tc-file" class="field-label">{{ __('translations.center.file') }}</label>
                    <input id="tc-file" type="file" wire:model="importFile" accept=".csv,.xlsx,text/csv" class="input">
                    @error('importFile')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="btn-secondary" wire:loading.attr="disabled">{{ __('translations.center.import') }}</button>
            </form>
            @if ($importResult)
                <div class="mt-4 text-sm" role="status">
                    <p>{{ __('translations.center.import_done', ['drafts' => $importResult['drafts'], 'unchanged' => $importResult['unchanged']]) }}</p>
                    @if ($importResult['errors'])
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-bad" role="list">
                            @foreach ($importResult['errors'] as $e)<li>{{ $e }}</li>@endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </section>

        <section class="panel p-5" aria-labelledby="tc-templates">
            <h2 id="tc-templates" class="text-base font-semibold text-heading">{{ __('translations.center.templates_title') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('translations.center.templates_hint', ['count' => $templateCount]) }}</p>
            @if ($templateGaps)
                <ul class="mt-3 space-y-1 text-sm" role="list">
                    @foreach ($templateGaps as $gap)
                        <li><span class="badge-bad">{{ __('translations.editor.missing') }}</span>
                            {{ __('messages.types.'.$gap['type']) }} · {{ __('messages.channels.'.$gap['channel']) }} · {{ implode(', ', array_map(fn ($l) => __('crm.languages.'.$l), $gap['missing'])) }}</li>
                    @endforeach
                </ul>
            @else
                <p class="mt-3 text-sm text-ok">{{ __('translations.center.templates_complete') }}</p>
            @endif
            @can('communications.templates')
                <a href="{{ route('admin.communication.templates') }}" wire:navigate class="btn-link mt-3 inline-block text-sm">{{ __('translations.center.templates_open') }}</a>
            @endcan
        </section>
    </div>

    {{-- The editing panel; in translation mode the layout already carries it. --}}
    @unless (\App\Http\Middleware\TranslationMode::activeFor(request()))
        <livewire:translations.inline-editor />
    @endunless
</div>
