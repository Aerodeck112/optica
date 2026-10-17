<x-layouts.auth :title="__('auth.reset.title')">
    <h1 class="text-2xl font-semibold">{{ __('auth.reset.title') }}</h1>
    <form method="POST" action="{{ route('crm.password.update') }}" class="mt-8 space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div>
            <label for="email" class="field-label">{{ __('auth.login.email') }}</label>
            <input id="email" name="email" type="email" autocomplete="username" required value="{{ old('email', $email) }}" class="input"
                   @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            @error('email')<p id="email-error" class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="password" class="field-label">{{ __('auth.reset.new_password') }}</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required class="input" aria-describedby="password-hint @error('password') password-error @enderror"
                   @error('password') aria-invalid="true" @enderror>
            <p id="password-hint" class="field-hint">{{ __('auth.reset.password_rules') }}</p>
            @error('password')<p id="password-error" class="field-error">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="password_confirmation" class="field-label">{{ __('auth.reset.confirm_password') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="input">
        </div>
        <button type="submit" class="btn-primary w-full">{{ __('auth.reset.save') }}</button>
    </form>
</x-layouts.auth>
