<x-layouts.auth :title="__('auth.reset.title')">
    <h1 class="text-2xl font-semibold">{{ __('auth.reset.title') }}</h1>
    <p class="mt-2 text-sm text-muted">{{ __('auth.reset.request_intro') }}</p>
    @if (session('status'))
        <p class="mt-6 rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ session('status') }}</p>
    @endif
    <form method="POST" action="{{ route('crm.password.email') }}" class="mt-8 space-y-5">
        @csrf
        <div>
            <label for="email" class="field-label">{{ __('auth.login.email') }}</label>
            <input id="email" name="email" type="email" autocomplete="username" required value="{{ old('email') }}" class="input"
                   @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            @error('email')<p id="email-error" class="field-error">{{ $message }}</p>@enderror
        </div>
        <button type="submit" class="btn-primary w-full">{{ __('auth.reset.send') }}</button>
    </form>
    <p class="mt-6 text-sm"><a href="{{ route('crm.login') }}" class="btn-link">{{ __('auth.reset.back') }}</a></p>
</x-layouts.auth>
