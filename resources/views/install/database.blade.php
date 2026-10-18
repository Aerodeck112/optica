<x-layouts.install :step="$step" :steps="$steps">
    <p class="text-sm text-muted">{{ __('install.db.intro') }}</p>
    <form method="POST" action="{{ route('install.database') }}" class="mt-6 grid gap-4 sm:grid-cols-[1fr_8rem]">
        @csrf
        <div>
            <label for="host" class="field-label">{{ __('install.db.host') }}</label>
            <input id="host" name="host" required class="input" value="{{ old('host', $env['DB_HOST'] ?? 'localhost') }}">
        </div>
        <div>
            <label for="port" class="field-label">{{ __('install.db.port') }}</label>
            <input id="port" name="port" inputmode="numeric" required class="input" value="{{ old('port', $env['DB_PORT'] ?? '3306') }}">
        </div>
        <div class="sm:col-span-2">
            <label for="database" class="field-label">{{ __('install.db.database') }}</label>
            <input id="database" name="database" required class="input" value="{{ old('database', $env['DB_DATABASE'] ?? '') }}">
        </div>
        <div class="sm:col-span-2">
            <label for="username" class="field-label">{{ __('install.db.username') }}</label>
            <input id="username" name="username" required autocomplete="off" class="input" value="{{ old('username', $env['DB_USERNAME'] ?? '') }}">
        </div>
        <div class="sm:col-span-2">
            <label for="password" class="field-label">{{ __('install.db.password') }}</label>
            <input id="password" name="password" type="password" autocomplete="new-password" class="input">
        </div>
        <div class="sm:col-span-2"><button type="submit" class="btn-primary">{{ __('install.db.submit') }}</button></div>
    </form>
</x-layouts.install>
