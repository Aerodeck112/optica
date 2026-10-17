<x-layouts.auth :title="__('auth.login.title')">
    <h1 class="text-2xl font-semibold">{{ __('auth.login.title') }}</h1>
    <p class="mt-2 text-sm text-muted">{{ __('auth.login.intro') }}</p>

    @if (session('status'))
        <p class="mt-6 rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('crm.login.store') }}" class="mt-8 space-y-5" novalidate>
        @csrf
        <div>
            <label for="email" class="field-label">{{ __('auth.login.email') }}</label>
            <input id="email" name="email" type="email" autocomplete="username" required autofocus
                   value="{{ old('email') }}" class="input" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            @error('email')<p id="email-error" class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="password" class="field-label">{{ __('auth.login.password') }}</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required class="input"
                   @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
            @error('password')<p id="password-error" class="field-error">{{ $message }}</p>@enderror
        </div>
        <label class="flex min-h-11 items-center gap-3 text-sm">
            <input type="checkbox" name="remember" value="1" class="h-5 w-5 rounded border-line text-ink focus:ring-ink">
            {{ __('auth.login.remember') }}
        </label>
        <button type="submit" class="btn-primary w-full">{{ __('auth.login.submit') }}</button>
    </form>

    <p class="mt-6 text-sm"><a href="{{ route('crm.password.request') }}" class="btn-link">{{ __('auth.login.forgot') }}</a></p>

    <x-auth-language-links />
</x-layouts.auth>
