@props(['offer' => false])
{{-- In-page translation editor (TRD-01, TRD-02). Present only when the user switched the mode on. --}}
@if ($offer && ! \App\Http\Middleware\TranslationMode::activeFor(request()) && auth()->user()?->can('translations.edit'))
    {{-- On the public site, staff allowed to correct texts can switch the mode on from the page itself. --}}
    <form method="POST" action="{{ route('crm.translation-mode') }}" class="fixed bottom-4 left-4 z-40">
        @csrf
        <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
        <button type="submit" class="rounded-full bg-ink px-4 py-2 text-sm font-semibold text-white shadow-lg hover:bg-ink-900">{{ __('translations.mode.turn_on') }}</button>
    </form>
@endif
@if (\App\Http\Middleware\TranslationMode::activeFor(request()))
    <livewire:translations.inline-editor />
    <div class="fixed bottom-4 left-4 z-40 flex items-center gap-2 rounded-full bg-ink px-4 py-2 text-sm text-white shadow-lg" data-tr-skip role="status">
        <span>{{ __('translations.mode.on') }}</span>
        <form method="POST" action="{{ route('crm.translation-mode') }}">
            @csrf
            <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
            <button type="submit" class="font-semibold text-lens underline">{{ __('translations.mode.turn_off') }}</button>
        </form>
    </div>
    <style>
        [data-tr-keys] { outline: 2px dashed var(--color-lens, #f5c400); outline-offset: 2px; cursor: help; }
        [data-tr-keys][data-tr-missing] { outline-color: var(--color-bad, #b42318); }
        [data-tr-keys]:hover { background: color-mix(in srgb, var(--color-lens, #f5c400) 25%, transparent); }
    </style>
    {{-- Runs once: wire:navigate keeps listeners; each new page brings its own dictionary. --}}
    <script data-navigate-once>
        (() => {
            const norm = (s) => (s || '').replace(/\s+/g, ' ').trim();
            let byText = new Map();
            let patterns = [];
            const escape = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const build = () => {
                byText = new Map();
                patterns = [];
                for (const [key, entry] of Object.entries(window.__trDict || {})) {
                    const text = norm(entry.text);
                    if (text.length < 2) continue;
                    if (entry.pattern) {
                        // Template with :variables; the page shows it filled in, so it is matched as a pattern.
                        const literal = text.replace(/:[A-Za-z_][A-Za-z0-9_]*/g, '');
                        if (literal.trim().length < 3) continue;
                        const source = '^' + escape(text).replace(/:[A-Za-z_][A-Za-z0-9_]*/g, '.+?') + '$';
                        patterns.push({ re: new RegExp(source, 's'), item: { keys: [key], missing: entry.fallback } });
                        continue;
                    }
                    if (!byText.has(text)) byText.set(text, { keys: [], missing: false });
                    const item = byText.get(text);
                    item.keys.push(key);
                    item.missing = item.missing || entry.fallback;
                }
            };
            const find = (raw) => {
                const text = norm(raw);
                if (!text) return null;
                const exact = byText.get(text);
                if (exact) return exact;
                for (const p of patterns) if (p.re.test(text)) return p.item;
                return null;
            };
            const skip = (el) => !el || el.closest('[data-tr-skip], script, style, textarea, select, [contenteditable]');
            const mark = (el, item) => {
                const keys = new Set((el.dataset.trKeys || '').split(',').filter(Boolean));
                item.keys.forEach((k) => keys.add(k));
                el.dataset.trKeys = [...keys].join(',');
                if (item.missing) el.dataset.trMissing = '1';
            };
            const scan = () => {
                if (!byText.size && !patterns.length) return;
                const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
                for (let node = walker.nextNode(); node; node = walker.nextNode()) {
                    if (skip(node.parentElement)) continue;
                    const item = find(node.nodeValue);
                    if (item) mark(node.parentElement, item);
                }
                document.querySelectorAll('[placeholder], [aria-label], [title], img[alt], input[type=submit][value]').forEach((el) => {
                    if (skip(el)) return;
                    for (const attr of ['placeholder', 'aria-label', 'title', 'alt', 'value']) {
                        const item = el.hasAttribute(attr) && find(el.getAttribute(attr));
                        if (item) mark(el, item);
                    }
                });
                // Buttons and links whose whole text is one translation (text split by icons).
                document.querySelectorAll('button, a, label, th, h1, h2, h3, p, span, li, dt, summary, legend, option').forEach((el) => {
                    if (skip(el) || el.dataset.trKeys) return;
                    const item = find(el.textContent);
                    if (item) mark(el, item);
                });
            };
            // A marked element opens the editor; its own action (button, link, form) does not run (TRD-02).
            const intercept = (event) => {
                const el = event.target.closest && event.target.closest('[data-tr-keys]');
                if (!el || el.closest('[data-tr-skip]')) return;
                event.preventDefault();
                event.stopImmediatePropagation();
                if (event.type === 'click') window.Livewire?.dispatch('tr-open', { keys: el.dataset.trKeys.split(',') });
            };
            ['click', 'mousedown', 'pointerdown', 'submit'].forEach((type) => document.addEventListener(type, intercept, true));
            window.addEventListener('tr-dict', () => { build(); scan(); });
            document.addEventListener('livewire:navigated', scan);
            let timer;
            new MutationObserver(() => { clearTimeout(timer); timer = setTimeout(scan, 300); }).observe(document.documentElement, { childList: true, subtree: true });
            // After publishing, the page shows the new text without reloading.
            window.addEventListener('tr-preview', (event) => {
                const { key, text } = event.detail;
                document.querySelectorAll('[data-tr-keys]').forEach((el) => {
                    if (!el.dataset.trKeys.split(',').includes(key)) return;
                    const old = norm(window.__trDict[key]?.text);
                    for (const node of el.childNodes) {
                        if (node.nodeType === 3 && norm(node.nodeValue) === old) node.nodeValue = text;
                    }
                });
                if (window.__trDict[key]) window.__trDict[key].text = text;
                build();
            });
            if (window.__trDict) { build(); scan(); }
        })();
    </script>
@endif
