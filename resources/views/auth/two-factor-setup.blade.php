<x-layouts.auth :title="__('auth.two_factor.setup_title')">
    <h1 class="text-2xl font-semibold">{{ __('auth.two_factor.setup_title') }}</h1>
    @if ($required)
        <p class="mt-3 rounded-lg bg-warn-bg px-4 py-3 text-sm text-warn">{{ __('auth.two_factor.setup_required') }}</p>
    @endif
    <p class="mt-4 text-sm">{{ __('auth.two_factor.setup_steps') }}</p>

    <div class="mt-6 inline-block rounded-xl bg-white p-3 ring-1 ring-line">{!! $qr !!}</div>
    <p class="mt-3 text-xs text-muted">{{ __('auth.two_factor.manual_key') }}</p>
    <p class="select-all font-semibold tracking-wider text-heading">{{ $secret }}</p>

    <form method="POST" action="{{ route('crm.two-factor.confirm') }}" class="mt-8 space-y-5">
        @csrf
        <div>
            <label for="code" class="field-label">{{ __('auth.two_factor.code') }}</label>
            <input id="code" name="code" type="text" inputmode="numeric" maxlength="7" autocomplete="one-time-code" required
                   class="input text-center text-xl tracking-[0.4em]" @error('code') aria-invalid="true" aria-describedby="code-error" @enderror>
            @error('code')<p id="code-error" class="field-error">{{ $message }}</p>@enderror
        </div>
        <button type="submit" class="btn-primary w-full">{{ __('auth.two_factor.activate') }}</button>
    </form>
    <form method="POST" action="{{ route('crm.logout') }}" class="mt-6">
        @csrf
        <button class="btn-link text-sm">{{ __('auth.logout') }}</button>
    </form>
</x-layouts.auth>
