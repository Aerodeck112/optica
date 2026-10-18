<x-layouts.install :step="$step" :steps="$steps">
    @if ($hasAdmin)
        <p class="text-sm">{{ __('install.admin.exists') }}</p>
        <a href="{{ route('install.step', 'settings') }}" class="btn-primary mt-6">{{ __('install.continue') }}</a>
    @else
        <p class="text-sm text-muted">{{ __('install.admin.intro') }}</p>
        <form method="POST" action="{{ route('install.admin') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="name" class="field-label">{{ __('install.admin.name') }}</label>
                <input id="name" name="name" required autocomplete="name" class="input" value="{{ old('name') }}">
            </div>
            <div>
                <label for="email" class="field-label">{{ __('install.admin.email') }}</label>
                <input id="email" name="email" type="email" required autocomplete="username" class="input" value="{{ old('email') }}">
            </div>
            <div>
                <label for="password" class="field-label">{{ __('install.admin.password') }}</label>
                <input id="password" name="password" type="password" required autocomplete="new-password" class="input" aria-describedby="password-hint">
                <p id="password-hint" class="mt-1 text-xs text-muted">{{ __('install.admin.password_hint') }}</p>
            </div>
            <div>
                <label for="password_confirmation" class="field-label">{{ __('install.admin.password_confirmation') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="input">
            </div>
            <div>
                <label for="locale" class="field-label">{{ __('install.admin.locale') }}</label>
                <select id="locale" name="locale" class="input">
                    @foreach (['ro' => 'Română', 'hu' => 'Magyar'] as $code => $label)<option value="{{ $code }}" @selected(old('locale', app()->getLocale()) === $code)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <button type="submit" class="btn-primary">{{ __('install.admin.submit') }}</button>
        </form>
    @endif
</x-layouts.install>
