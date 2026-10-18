<x-layouts.install :step="$step" :steps="$steps">
    <form method="POST" action="{{ route('install.settings') }}" class="space-y-4">
        @csrf
        <div>
            <label for="url" class="field-label">{{ __('install.settings.url') }}</label>
            <input id="url" name="url" type="url" required class="input" value="{{ old('url', $url) }}">
        </div>
        <div>
            <label for="timezone" class="field-label">{{ __('install.settings.timezone') }}</label>
            <select id="timezone" name="timezone" class="input">
                @foreach ($timezones as $tz)<option value="{{ $tz }}" @selected(old('timezone', 'Europe/Bucharest') === $tz)>{{ $tz }}</option>@endforeach
            </select>
        </div>
        <fieldset class="space-y-4 border-t border-line pt-4">
            <legend class="text-base font-semibold text-heading">{{ __('install.settings.smtp') }}</legend>
            <p class="text-sm text-muted">{{ __('install.settings.smtp_hint') }}</p>
            <div class="grid gap-4 sm:grid-cols-[1fr_8rem]">
                <div>
                    <label for="mail_host" class="field-label">{{ __('install.settings.mail_host') }}</label>
                    <input id="mail_host" name="mail_host" class="input" value="{{ old('mail_host') }}">
                </div>
                <div>
                    <label for="mail_port" class="field-label">{{ __('install.settings.mail_port') }}</label>
                    <input id="mail_port" name="mail_port" inputmode="numeric" class="input" value="{{ old('mail_port', '587') }}">
                </div>
            </div>
            <div>
                <label for="mail_scheme" class="field-label">{{ __('install.settings.mail_scheme') }}</label>
                <select id="mail_scheme" name="mail_scheme" class="input">
                    <option value="smtp" @selected(old('mail_scheme') !== 'smtps')>{{ __('install.settings.mail_scheme_smtp') }}</option>
                    <option value="smtps" @selected(old('mail_scheme') === 'smtps')>{{ __('install.settings.mail_scheme_smtps') }}</option>
                </select>
            </div>
            <div>
                <label for="mail_username" class="field-label">{{ __('install.settings.mail_username') }}</label>
                <input id="mail_username" name="mail_username" autocomplete="off" class="input" value="{{ old('mail_username') }}">
            </div>
            <div>
                <label for="mail_password" class="field-label">{{ __('install.settings.mail_password') }}</label>
                <input id="mail_password" name="mail_password" type="password" autocomplete="new-password" class="input">
            </div>
            <div>
                <label for="mail_from" class="field-label">{{ __('install.settings.mail_from') }}</label>
                <input id="mail_from" name="mail_from" type="email" class="input" value="{{ old('mail_from') }}">
            </div>
            <div>
                <button type="submit" name="action" value="test" class="btn-secondary">{{ __('install.settings.test') }}</button>
                <p class="mt-1 text-xs text-muted">{{ __('install.settings.test_hint') }}</p>
            </div>
        </fieldset>
        <button type="submit" name="action" value="save" class="btn-primary">{{ __('install.settings.submit') }}</button>
    </form>
</x-layouts.install>
