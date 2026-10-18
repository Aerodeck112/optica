<x-layouts.install>
    <h1 class="text-xl font-semibold text-heading">{{ __('install.token.heading') }}</h1>
    <p class="mt-2 text-sm">{{ __('install.token.intro') }}</p>
    <p class="mt-3 text-sm">{{ __('install.token.file') }}: <code class="rounded bg-paper px-1.5 py-0.5 ring-1 ring-line">{{ $tokenFile }}</code></p>
    <form method="POST" action="{{ route('install.token') }}" class="mt-6 space-y-4">
        @csrf
        <div>
            <label for="token" class="field-label">{{ __('install.token.label') }}</label>
            <input id="token" name="token" type="text" autocomplete="off" spellcheck="false" required autofocus class="input font-mono">
        </div>
        <button type="submit" class="btn-primary">{{ __('install.token.submit') }}</button>
    </form>
</x-layouts.install>
