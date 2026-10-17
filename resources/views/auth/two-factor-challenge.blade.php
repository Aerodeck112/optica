<x-layouts.auth :title="__('auth.two_factor.challenge_title')">
    <h1 class="text-2xl font-semibold">{{ __('auth.two_factor.challenge_title') }}</h1>
    <p class="mt-2 text-sm text-muted">{{ __('auth.two_factor.challenge_intro') }}</p>

    <form method="POST" action="{{ auth()->check() ? route('crm.two-factor.reverify.store') : route('crm.two-factor.verify') }}" class="mt-8 space-y-5">
        @csrf
        <div>
            <label for="code" class="field-label">{{ __('auth.two_factor.code') }}</label>
            <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required autofocus
                   class="input text-center text-xl tracking-[0.4em]" @error('code') aria-invalid="true" aria-describedby="code-error" @enderror>
            @error('code')<p id="code-error" class="field-error">{{ $message }}</p>@enderror
        </div>
        <button type="submit" class="btn-primary w-full">{{ __('auth.two_factor.verify') }}</button>
    </form>
    <p class="mt-6 text-sm"><a href="{{ route('crm.login') }}" class="btn-link">{{ __('auth.reset.back') }}</a></p>
</x-layouts.auth>
