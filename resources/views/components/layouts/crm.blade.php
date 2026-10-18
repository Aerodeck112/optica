@props(['title' => null])
@php($navigation = \App\Support\Ui\Navigation::for(auth()->user()))
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}Optica Optofarm CRM</title>
    <link rel="icon" href="{{ asset('images/logo-optofarm.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-dvh" x-data="{ nav: false }" @keydown.escape.window="nav = false">
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-lg focus:bg-lens focus:px-4 focus:py-2 focus:text-ink">{{ __('crm.skip_to_content') }}</a>

<div class="lg:grid lg:grid-cols-[15.5rem_minmax(0,1fr)]">
    {{-- Mobile overlay --}}
    <div x-show="nav" x-cloak class="fixed inset-0 z-30 bg-ink-900/50 lg:hidden" @click="nav = false"></div>

    <aside id="crm-nav"
           class="fixed inset-y-0 left-0 z-40 flex w-[15.5rem] -translate-x-full flex-col bg-ink text-mist-300 transition-transform lg:sticky lg:top-0 lg:h-dvh lg:translate-x-0"
           :class="nav && 'translate-x-0'">
        <div class="flex items-center gap-3 px-5 pb-6 pt-5">
            <img src="{{ asset('images/logo-optofarm.png') }}" alt="" class="h-11 w-11 rounded-full bg-white/95 p-0.5">
            <div class="leading-tight">
                <p class="text-sm font-semibold text-white">Optica Optofarm</p>
                <p class="text-xs text-mist-300/80">CRM</p>
            </div>
        </div>
        <nav class="flex-1 space-y-6 overflow-y-auto px-3 pb-6" aria-label="{{ __('crm.nav.label') }}">
            @foreach ($navigation as $group)
                <div>
                    @if ($group['label'])
                        <p class="px-3 pb-2 text-xs font-medium text-mist-300/70">{{ __($group['label']) }}</p>
                    @endif
                    <ul class="space-y-0.5">
                        @foreach ($group['items'] as $item)
                            @php($active = request()->routeIs(...explode('|', $item['active'])))
                            <li>
                                <a href="{{ route($item['route']) }}" wire:navigate
                                   @class([
                                       'relative flex min-h-11 items-center rounded-lg px-3 text-sm transition-colors',
                                       'bg-white/10 font-semibold text-white before:absolute before:inset-y-2 before:left-0 before:w-1 before:rounded-full before:bg-lens' => $active,
                                       'hover:bg-white/5 hover:text-white' => ! $active,
                                   ])
                                   @if ($active) aria-current="page" @endif>
                                    {{ __($item['label']) }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>
        <div class="border-t border-white/10 px-5 py-4 text-sm">
            <p class="truncate font-medium text-white">{{ auth()->user()->name }}</p>
            <p class="truncate text-xs text-mist-300/80">{{ auth()->user()->email }}</p>
            <form method="POST" action="{{ route('crm.logout') }}" class="mt-3">
                @csrf
                <button type="submit" class="text-sm font-semibold text-lens hover:underline">{{ __('auth.logout') }}</button>
            </form>
        </div>
    </aside>

    <div class="min-w-0">
        <header class="sticky top-0 z-20 flex min-h-16 items-center gap-3 border-b border-line bg-paper/95 px-4 backdrop-blur sm:px-6 lg:px-10">
            <button type="button" class="btn-secondary px-3 lg:hidden" @click="nav = true" aria-controls="crm-nav" :aria-expanded="nav">
                <span class="sr-only">{{ __('crm.nav.open') }}</span>
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 5h14v1.5H3zM3 9.25h14v1.5H3zM3 13.5h14V15H3z"/></svg>
            </button>
            <h1 class="min-w-0 flex-1 truncate text-lg font-semibold">{{ $title }}</h1>

            @can('translations.edit')
                @unless (\App\Http\Middleware\TranslationMode::activeFor(request()))
                    <form method="POST" action="{{ route('crm.translation-mode') }}" class="hidden sm:flex">
                        @csrf
                        <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
                        <button type="submit" class="btn-secondary min-h-9 px-3 text-sm">{{ __('translations.mode.turn_on') }}</button>
                    </form>
                @endunless
            @endcan

            <form method="POST" action="{{ route('crm.locale') }}" class="flex" x-data
                  @submit="window.OptofarmDrafts && window.OptofarmDrafts.save()">
                @csrf
                <input type="hidden" name="return_to" value="{{ request()->getRequestUri() }}">
                <fieldset class="flex rounded-lg bg-white p-0.5 ring-1 ring-line">
                    <legend class="sr-only">{{ __('crm.language') }}</legend>
                    @foreach (config('optica.locales') as $locale)
                        <button type="submit" name="locale" value="{{ $locale }}"
                                @class([
                                    'min-h-9 min-w-11 rounded-md px-2.5 text-sm font-semibold uppercase',
                                    'bg-ink text-white' => app()->getLocale() === $locale,
                                    'text-ink hover:bg-mist' => app()->getLocale() !== $locale,
                                ])
                                @if (app()->getLocale() === $locale) aria-pressed="true" @else aria-pressed="false" @endif
                                lang="{{ $locale }}" title="{{ __('crm.languages.'.$locale) }}">{{ $locale }}</button>
                    @endforeach
                </fieldset>
            </form>
        </header>

        <main id="main" class="px-4 py-6 sm:px-6 lg:px-10 lg:py-8">
            @if (session('status'))
                <div class="mb-6 rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ session('status') }}</div>
            @endif
            {{ $slot }}
        </main>
    </div>
</div>

<x-translation-mode />
@livewireScripts
<script>
    // Keeps unsaved form input across a language switch (LNG-02): saved before submit, restored on load.
    window.OptofarmDrafts = {
        key: 'optofarm-draft:' + location.pathname + location.search,
        selector: '[data-preserve] [name], [data-preserve] [wire\\:model], [data-preserve] [wire\\:model\\.live], [data-preserve] [wire\\:model\\.blur]',
        fieldKey(el) {
            return el.getAttribute('name') || el.getAttribute('wire:model') || el.getAttribute('wire:model.live') || el.getAttribute('wire:model.blur');
        },
        fields() {
            const groups = {};
            document.querySelectorAll(this.selector).forEach((el) => {
                const key = this.fieldKey(el);
                if (!key || el.type === 'file' || el.type === 'password' || el.type === 'hidden') return;
                (groups[key] ||= []).push(el);
            });
            return groups;
        },
        save() {
            const data = {};
            Object.entries(this.fields()).forEach(([key, els]) => {
                if (els[0].type === 'checkbox' && els.length > 1) {
                    data[key] = { list: els.filter((el) => el.checked).map((el) => el.value) };
                } else if (els[0].type === 'checkbox') {
                    data[key] = { checked: els[0].checked };
                } else {
                    data[key] = { value: els[0].value };
                }
            });
            try { sessionStorage.setItem(this.key, JSON.stringify(data)); } catch (e) {}
        },
        restore() {
            let data;
            try { data = JSON.parse(sessionStorage.getItem(this.key) || 'null'); sessionStorage.removeItem(this.key); } catch (e) { return; }
            if (!data) return;
            const groups = this.fields();
            Object.entries(data).forEach(([key, saved]) => {
                (groups[key] || []).forEach((el) => {
                    if ('list' in saved) el.checked = saved.list.includes(el.value);
                    else if ('checked' in saved) el.checked = saved.checked;
                    else el.value = saved.value;
                    el.dispatchEvent(new Event(el.type === 'checkbox' ? 'change' : 'input', { bubbles: true }));
                });
            });
        },
    };
    document.addEventListener('livewire:navigated', () => window.OptofarmDrafts.restore());
</script>
</body>
</html>
