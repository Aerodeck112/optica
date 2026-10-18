@php($tz = config('optica.clinic_timezone'))
<div class="max-w-7xl">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <x-admin.publish-state :model="$page" />
        @if ($page->published_at)
            <span class="text-xs text-muted">{{ __('crm.state.published_at', ['date' => $page->published_at->timezone($tz)->format('d.m.Y H:i')]) }}</span>
        @endif
        <a href="{{ route('cms.pages.index') }}" wire:navigate class="btn-link ml-auto text-sm">{{ __('crm.actions.back_to_list') }}</a>
    </div>

    <div aria-live="polite">
        @if ($notice)
            <div class="mb-6 rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ $notice }}</div>
        @endif
        @if ($conflict)
            <div class="mb-6 flex flex-wrap items-center gap-3 rounded-lg bg-warn-bg px-4 py-3 text-sm text-warn" role="alert">
                <p class="flex-1">{{ __('cms.pages.conflict') }}</p>
                <button type="button" class="btn-secondary" wire:click="reload">{{ __('cms.pages.reload') }}</button>
            </div>
        @endif
        @if ($errors->any())
            <div class="mb-6 rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad" role="alert">{{ __('cms.pages.fix_errors') }}</div>
        @endif
    </div>

    <form wire:submit="save" class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="min-w-0 space-y-8">
            <x-cms.bilingual base="draft.title" field="" :label="__('cms.pages.page_title')" required max="160" />

            <section aria-labelledby="sections-heading">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 id="sections-heading" class="text-base font-semibold">{{ __('cms.pages.sections') }}</h2>
                        <p class="text-sm text-muted">{{ __('cms.pages.sections_hint') }}</p>
                    </div>
                </div>

                <ol class="mt-4 space-y-3" role="list">
                    @forelse ($draft['sections'] as $i => $section)
                        @php($type = $section['type'])
                        @php($heading = $section['text']['ro']['heading'] ?? '')
                        <li class="panel" wire:key="section-{{ $section['id'] }}">
                            <div class="flex flex-wrap items-center gap-2 p-3 sm:p-4">
                                <button type="button" class="flex min-h-11 min-w-0 flex-1 items-center gap-3 text-left" wire:click="toggle({{ $i }})"
                                        aria-expanded="{{ $open === $i ? 'true' : 'false' }}" aria-controls="section-body-{{ $i }}">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-mist text-sm font-semibold text-ink">{{ $i + 1 }}</span>
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-heading">{{ __('cms.sections.'.$type) }}</span>
                                        <span class="block truncate text-xs text-muted">{{ $heading !== '' ? $heading : __('cms.pages.no_heading') }}</span>
                                    </span>
                                </button>
                                <div class="flex items-center gap-1">
                                    <button type="button" class="btn-secondary min-h-10 px-2.5" wire:click="moveSection({{ $i }}, -1)" @disabled($loop->first)
                                            aria-label="{{ __('cms.pages.move_up', ['n' => $i + 1]) }}">
                                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 5l5 6H5z"/></svg>
                                    </button>
                                    <button type="button" class="btn-secondary min-h-10 px-2.5" wire:click="moveSection({{ $i }}, 1)" @disabled($loop->last)
                                            aria-label="{{ __('cms.pages.move_down', ['n' => $i + 1]) }}">
                                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 15l-5-6h10z"/></svg>
                                    </button>
                                    <button type="button" class="btn-danger min-h-10 px-2.5" wire:click="removeSection({{ $i }})"
                                            wire:confirm="{{ __('cms.pages.confirm_remove_section') }}" aria-label="{{ __('cms.pages.remove_section', ['n' => $i + 1]) }}">
                                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M7 3h6l1 2h3v2H3V5h3zm-2 5h10l-1 9H6z"/></svg>
                                    </button>
                                </div>
                            </div>

                            @if ($open === $i)
                                <div id="section-body-{{ $i }}" class="space-y-5 border-t border-line p-4 sm:p-5">
                                    @php($base = "draft.sections.$i.text")
                                    @php($fields = \App\Domain\Cms\Sections::TYPES[$type]['text'])
                                    @if (in_array('heading', $fields, true))
                                        <x-cms.bilingual :base="$base" field="heading" :label="__('cms.fields.heading')" max="300" />
                                    @endif
                                    @if (in_array('body', $fields, true))
                                        <x-cms.bilingual :base="$base" field="body" :label="__('cms.fields.body')" textarea rows="6" :hint="__('cms.fields.body_hint')" />
                                    @endif
                                    @if (in_array('button_label', $fields, true))
                                        <x-cms.bilingual :base="$base" field="button_label" :label="__('cms.fields.button_label')" max="60" />
                                    @endif

                                    @if ($type === 'faq')
                                        <fieldset class="space-y-4">
                                            <legend class="field-label">{{ __('cms.fields.faq_items') }}</legend>
                                            @foreach ($section['text']['ro']['items'] ?? [] as $j => $item)
                                                <div class="rounded-lg bg-paper p-4 ring-1 ring-line" wire:key="faq-{{ $section['id'] }}-{{ $j }}">
                                                    <div class="grid gap-4 md:grid-cols-2">
                                                        @foreach (config('optica.locales') as $locale)
                                                            <div class="space-y-2">
                                                                <p class="text-xs font-medium text-muted">{{ __('crm.languages.'.$locale) }}</p>
                                                                <label class="sr-only" for="faq-{{ $i }}-{{ $j }}-{{ $locale }}-q">{{ __('cms.fields.question') }}</label>
                                                                <input id="faq-{{ $i }}-{{ $j }}-{{ $locale }}-q" type="text" class="input" lang="{{ $locale }}" placeholder="{{ __('cms.fields.question') }}"
                                                                       wire:model.blur="draft.sections.{{ $i }}.text.{{ $locale }}.items.{{ $j }}.q">
                                                                <label class="sr-only" for="faq-{{ $i }}-{{ $j }}-{{ $locale }}-a">{{ __('cms.fields.answer') }}</label>
                                                                <textarea id="faq-{{ $i }}-{{ $j }}-{{ $locale }}-a" rows="3" class="input" lang="{{ $locale }}" placeholder="{{ __('cms.fields.answer') }}"
                                                                          wire:model.blur="draft.sections.{{ $i }}.text.{{ $locale }}.items.{{ $j }}.a"></textarea>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                    <button type="button" class="btn-link mt-2 text-sm text-bad" wire:click="removeItem({{ $i }}, {{ $j }})">{{ __('cms.fields.remove_question') }}</button>
                                                </div>
                                            @endforeach
                                            <button type="button" class="btn-secondary" wire:click="addItem({{ $i }})">{{ __('cms.fields.add_question') }}</button>
                                        </fieldset>
                                    @endif

                                    @php($data = $section['data'])
                                    @if (array_key_exists('image_id', $data))
                                        <x-cms.image-field :target="'s.'.$i.'.image_id'" :label="__('cms.fields.image')" :ids="[$data['image_id']]" :media="$media" />
                                    @endif
                                    @if (array_key_exists('image_ids', $data))
                                        <x-cms.image-field :target="'s.'.$i.'.image_ids'" :label="$type === 'education' ? __('cms.fields.compare_images') : __('cms.fields.images')"
                                                           :ids="$data['image_ids']" :media="$media" multiple :hint="$type === 'education' ? __('cms.fields.compare_images_hint') : null" />
                                    @endif
                                    <div class="grid gap-5 md:grid-cols-2">
                                        @if (array_key_exists('button_target', $data))
                                            <div>
                                                <label for="target-{{ $i }}" class="field-label">{{ __('cms.fields.button_target') }}</label>
                                                <input id="target-{{ $i }}" type="text" class="input" list="targets" wire:model.blur="draft.sections.{{ $i }}.data.button_target"
                                                       @error("draft.sections.$i.data.button_target") aria-invalid="true" @enderror aria-describedby="target-{{ $i }}-hint">
                                                <p id="target-{{ $i }}-hint" class="field-hint">{{ __('cms.fields.button_target_hint') }}</p>
                                                @error("draft.sections.$i.data.button_target")<p class="field-error">{{ $message }}</p>@enderror
                                            </div>
                                        @endif
                                        @if (array_key_exists('image_side', $data))
                                            <div>
                                                <label for="side-{{ $i }}" class="field-label">{{ __('cms.fields.image_side') }}</label>
                                                <select id="side-{{ $i }}" class="input" wire:model="draft.sections.{{ $i }}.data.image_side">
                                                    <option value="right">{{ __('cms.fields.right') }}</option>
                                                    <option value="left">{{ __('cms.fields.left') }}</option>
                                                </select>
                                            </div>
                                        @endif
                                        @if (array_key_exists('lead_type', $data))
                                            <div>
                                                <label for="lead-{{ $i }}" class="field-label">{{ __('cms.fields.lead_type') }}</label>
                                                <select id="lead-{{ $i }}" class="input" wire:model="draft.sections.{{ $i }}.data.lead_type">
                                                    @foreach (\App\Models\Lead::TYPES as $lt)
                                                        <option value="{{ $lt }}">{{ __('cms.lead_types.'.$lt) }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endif
                                        @if (array_key_exists('tool', $data))
                                            <div>
                                                <label for="tool-{{ $i }}" class="field-label">{{ __('cms.fields.tool') }}</label>
                                                <select id="tool-{{ $i }}" class="input" wire:model="draft.sections.{{ $i }}.data.tool">
                                                    <option value="">—</option>
                                                    @foreach (\App\Livewire\Cms\PageEditor::TOOLS as $tool)
                                                        <option value="{{ $tool }}">{{ __('cms.tools.'.$tool) }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endif
                                    </div>
                                    @if (array_key_exists('show_phones', $data))
                                        <x-admin.toggle :model="'draft.sections.'.$i.'.data.show_phones'" :label="__('cms.fields.show_phones')" />
                                    @endif
                                    @if (in_array($type, ['services', 'doctors', 'locations', 'offers'], true))
                                        <p class="rounded-lg bg-mist/60 px-4 py-3 text-sm text-ink">{{ __('cms.pages.auto_list') }}</p>
                                    @endif
                                </div>
                            @endif
                        </li>
                    @empty
                        <li class="panel p-8 text-center text-sm text-muted">{{ __('cms.pages.no_sections') }}</li>
                    @endforelse
                </ol>

                <div class="mt-4 flex flex-wrap items-end gap-3">
                    <div class="w-full max-w-xs">
                        <label for="new-type" class="field-label">{{ __('cms.pages.add_section') }}</label>
                        <select id="new-type" wire:model="newType" class="input">
                            @foreach ($types as $t)
                                <option value="{{ $t }}">{{ __('cms.sections.'.$t) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="button" class="btn-secondary" wire:click="addSection">{{ __('cms.pages.add') }}</button>
                </div>
                <datalist id="targets">
                    <option value="booking">{{ __('cms.fields.target_booking') }}</option>
                    @foreach ($pages as $p)
                        <option value="page:{{ $p->key }}">{{ $p->title() }}</option>
                    @endforeach
                </datalist>
            </section>
        </div>

        <aside class="space-y-6">
            <div class="panel space-y-3 p-4 lg:sticky lg:top-20">
                <button type="submit" class="btn-secondary w-full" wire:loading.attr="disabled">{{ __('crm.actions.save_draft') }}</button>
                @can('cms.publish')
                    <button type="button" class="btn-primary w-full" wire:click="publish" wire:loading.attr="disabled">{{ __('crm.actions.publish') }}</button>
                @else
                    <p class="text-xs text-muted">{{ __('cms.pages.no_publish_right') }}</p>
                @endcan
                <div class="flex flex-wrap gap-x-4 gap-y-1 pt-1 text-sm">
                    <span class="text-muted">{{ __('cms.pages.preview') }}:</span>
                    <a href="{{ route('site.preview', $page) }}" target="_blank" rel="noopener" class="btn-link">RO</a>
                    <a href="{{ route('site.hu.preview', $page) }}" target="_blank" rel="noopener" class="btn-link">HU</a>
                </div>
                <p class="text-xs text-muted">{{ __('cms.pages.preview_hint') }}</p>
                @if ($page->published && $hasChanges)
                    <button type="button" class="btn-link text-sm text-bad" wire:click="discardChanges" wire:confirm="{{ __('cms.pages.confirm_discard') }}">{{ __('cms.pages.discard') }}</button>
                @endif
            </div>

            <details class="panel p-4" @if ($errors->has('slug_*') || $errors->has('draft.seo.*')) open @endif>
                <summary class="flex min-h-11 cursor-pointer items-center text-sm font-semibold">{{ __('cms.pages.seo') }}</summary>
                @can('seo.manage')
                    <div class="mt-3 space-y-4">
                        @if ($page->template === 'home')
                            <p class="text-xs text-muted">{{ __('cms.pages.home_slug') }}</p>
                        @else
                            <x-admin.text model="slug_ro" :label="__('cms.pages.slug').' (RO)'" />
                            <x-admin.text model="slug_hu" :label="__('cms.pages.slug').' (HU)'" :hint="__('cms.pages.slug_change_hint')" />
                        @endif
                        @foreach (config('optica.locales') as $locale)
                            <fieldset class="space-y-3 border-t border-line pt-3">
                                <legend class="text-xs font-semibold uppercase text-muted">{{ __('crm.languages.'.$locale) }}</legend>
                                <x-admin.text :model="'draft.seo.'.$locale.'.title'" :label="__('cms.pages.seo_title')" maxlength="70" :hint="__('cms.pages.seo_title_hint')" />
                                <div>
                                    <label for="seo-desc-{{ $locale }}" class="field-label">{{ __('cms.pages.seo_description') }}</label>
                                    <textarea id="seo-desc-{{ $locale }}" rows="3" maxlength="300" class="input" lang="{{ $locale }}" wire:model="draft.seo.{{ $locale }}.description"></textarea>
                                    @error('draft.seo.'.$locale.'.description')<p class="field-error">{{ $message }}</p>@enderror
                                </div>
                                <x-admin.toggle :model="'draft.seo.'.$locale.'.noindex'" :label="__('cms.pages.noindex')" />
                            </fieldset>
                        @endforeach
                        <x-cms.image-field target="og" :label="__('cms.pages.og_image')" :ids="[$draft['og_image_id'] ?? null]" :media="$media" />
                    </div>
                @else
                    <p class="mt-2 text-xs text-muted">{{ __('cms.pages.no_seo_right') }}</p>
                @endcan
            </details>

            <details class="panel p-4">
                <summary class="flex min-h-11 cursor-pointer items-center text-sm font-semibold">{{ __('cms.pages.versions') }}</summary>
                <ol class="mt-3 divide-y divide-line text-sm" role="list">
                    @forelse ($versions as $v)
                        <li class="flex items-center gap-3 py-2" wire:key="version-{{ $v->id }}">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">{{ __('cms.pages.version', ['number' => $v->number]) }}</p>
                                <p class="truncate text-xs text-muted">{{ $v->created_at->timezone($tz)->format('d.m.Y H:i') }} · {{ $v->author?->name ?? '—' }}</p>
                            </div>
                            <button type="button" class="btn-link text-xs" wire:click="restoreVersion({{ $v->id }})" wire:confirm="{{ __('cms.pages.confirm_restore') }}">{{ __('cms.pages.restore_to_draft') }}</button>
                        </li>
                    @empty
                        <li class="py-2 text-xs text-muted">{{ __('cms.pages.no_versions') }}</li>
                    @endforelse
                </ol>
            </details>
        </aside>
    </form>

    @if (! $page->is_system)
        @can('cms.publish')
            <x-admin.delete-zone :label="__('cms.pages.delete')" :warning="__('cms.pages.delete_warning')" />
        @endcan
    @endif

    @if ($pickerTarget)
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-black/40 sm:items-center sm:p-6" role="dialog" aria-modal="true" aria-labelledby="picker-title"
             x-data x-init="$nextTick(() => $el.querySelector('input')?.focus())" @keydown.escape.window="$wire.closePicker()">
            <div class="flex max-h-[90dvh] w-full max-w-4xl flex-col rounded-t-2xl bg-white sm:rounded-2xl">
                <div class="flex items-center gap-3 border-b border-line p-4">
                    <h2 id="picker-title" class="flex-1 text-base font-semibold">{{ __('cms.media.choose_title') }}</h2>
                    <button type="button" class="btn-secondary" wire:click="closePicker">{{ __('crm.actions.cancel') }}</button>
                </div>
                <div class="flex flex-wrap items-end gap-3 border-b border-line p-4">
                    <div class="w-full max-w-xs">
                        <label for="picker-search" class="field-label">{{ __('crm.actions.search') }}</label>
                        <input id="picker-search" type="search" class="input" wire:model.live.debounce.300ms="pickerSearch">
                    </div>
                    @can('media.manage')
                        <div>
                            <p class="field-label">{{ __('cms.media.upload_new') }}</p>
                            <label for="picker-upload" class="btn-secondary cursor-pointer focus-within:ring-2 focus-within:ring-ink">{{ __('cms.media.upload') }}
                                <input id="picker-upload" type="file" accept="image/jpeg,image/png,image/webp" wire:model="upload" class="sr-only">
                            </label>
                            <p wire:loading wire:target="upload" class="field-hint">{{ __('crm.uploading') }}</p>
                            @error('upload')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    @endcan
                </div>
                <ul class="grid flex-1 grid-cols-2 gap-3 overflow-y-auto p-4 sm:grid-cols-4 lg:grid-cols-5" role="list">
                    @forelse ($library as $m)
                        <li wire:key="lib-{{ $m->id }}">
                            <button type="button" class="group block w-full overflow-hidden rounded-lg text-left ring-1 ring-line hover:ring-2 hover:ring-ink focus-visible:ring-2 focus-visible:ring-ink" wire:click="pick({{ $m->id }})">
                                <img src="{{ $m->url(480) }}" alt="" class="aspect-[4/3] w-full object-cover" loading="lazy">
                                <span class="block truncate px-2 py-1.5 text-xs">{{ $m->altText() ?: ($m->original_name ?? '#'.$m->id) }}</span>
                            </button>
                        </li>
                    @empty
                        <li class="col-span-full py-10 text-center text-sm text-muted">{{ __('cms.media.empty') }}</li>
                    @endforelse
                </ul>
            </div>
        </div>
    @endif
</div>
