<div class="max-w-5xl">
    <form wire:submit="save" data-preserve class="space-y-10">
        <section class="space-y-5" aria-labelledby="sec-account">
            <h2 id="sec-account" class="text-base font-semibold">{{ __('users.account') }}</h2>
            <div class="grid gap-5 md:grid-cols-2">
                <x-admin.text model="name" :label="__('crm.fields.name')" required />
                <x-admin.text model="email" type="email" :label="__('crm.fields.email')" required :hint="$user ? null : __('users.email_invite_hint')" />
                <x-admin.text model="phone" type="tel" :label="__('crm.fields.phone')" />
                <div>
                    <label for="locale" class="field-label">{{ __('users.fields.locale') }}</label>
                    <select id="locale" wire:model="locale" class="input" aria-describedby="locale-hint">
                        @foreach (config('optica.locales') as $l)
                            <option value="{{ $l }}">{{ __('crm.languages.'.$l) }}</option>
                        @endforeach
                    </select>
                    <p id="locale-hint" class="field-hint">{{ __('users.locale_hint') }}</p>
                </div>
            </div>
            <x-admin.toggle model="is_active" :label="__('crm.fields.is_active')" :hint="__('users.active_hint')" />
            @error('is_active')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        </section>

        <section aria-labelledby="sec-roles">
            <h2 id="sec-roles" class="text-base font-semibold">{{ __('users.fields.roles') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('users.roles_hint') }}</p>
            @error('roleIds')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            <div class="panel mt-4 divide-y divide-line">
                @foreach ($roles as $r)
                    <label class="flex min-h-11 items-center gap-3 px-4 py-2 text-sm" wire:key="role-{{ $r->id }}">
                        <input type="checkbox" value="{{ $r->id }}" wire:model="roleIds" class="h-5 w-5 rounded border-line text-ink focus:ring-ink">
                        <span class="font-medium text-heading">{{ $r->label() }}</span>
                        @if (in_array($r->key, config('optica.security.two_factor_roles'), true))
                            <span class="badge-info">{{ __('users.requires_2fa') }}</span>
                        @endif
                    </label>
                @endforeach
            </div>
        </section>

        <section aria-labelledby="sec-locations">
            <h2 id="sec-locations" class="text-base font-semibold">{{ __('crm.fields.locations') }}</h2>
            <div class="mt-3"><x-admin.toggle model="all_locations" :label="__('users.all_locations')" :hint="__('users.all_locations_hint')" /></div>
            <div @class(['panel mt-4 grid divide-line sm:grid-cols-2', 'opacity-50' => $all_locations])>
                @foreach ($locations as $l)
                    <label class="flex min-h-11 items-center gap-3 border-b border-line px-4 py-2 text-sm" wire:key="ul-{{ $l->id }}">
                        <input type="checkbox" value="{{ $l->id }}" wire:model="locationIds" class="h-5 w-5 rounded border-line text-ink focus:ring-ink" @disabled($all_locations)>
                        {{ $l->name() }}
                    </label>
                @endforeach
            </div>
        </section>

        <section aria-labelledby="sec-specialist">
            <h2 id="sec-specialist" class="text-base font-semibold">{{ __('users.specialist_profile') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('users.specialist_hint') }}</p>
            <div class="mt-3 max-w-md">
                <label for="specialistId" class="sr-only">{{ __('users.specialist_profile') }}</label>
                <select id="specialistId" wire:model="specialistId" class="input">
                    <option value="">{{ __('users.no_specialist') }}</option>
                    @foreach ($specialists as $s)
                        <option value="{{ $s->id }}">{{ $s->display_name }}</option>
                    @endforeach
                </select>
                @error('specialistId')<p class="field-error">{{ $message }}</p>@enderror
            </div>
        </section>

        <div class="flex flex-wrap items-center gap-3 border-t border-line pt-6">
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ $user ? __('users.save') : __('users.create_and_invite') }}</button>
            <a href="{{ route('admin.users.index') }}" wire:navigate class="btn-link ml-auto text-sm">{{ __('crm.actions.back_to_list') }}</a>
        </div>
    </form>

    @if ($user)
        <section class="mt-12 space-y-6 border-t border-line pt-8" aria-labelledby="sec-security">
            <h2 id="sec-security" class="text-base font-semibold">{{ __('users.security') }}</h2>
            <dl class="grid gap-4 text-sm sm:grid-cols-3">
                <div><dt class="text-muted">{{ __('users.last_login') }}</dt><dd class="font-medium text-heading">{{ $user->last_login_at?->setTimezone(config('optica.clinic_timezone'))->format('d.m.Y H:i') ?? '—' }}</dd></div>
                <div><dt class="text-muted">{{ __('users.two_factor') }}</dt><dd class="font-medium text-heading">{{ $user->two_factor_confirmed_at ? __('users.two_factor_on') : __('users.two_factor_off') }}</dd></div>
            </dl>
            <div class="flex flex-wrap gap-3">
                <button type="button" class="btn-secondary" wire:click="invite" wire:confirm="{{ __('users.confirm_invite') }}">{{ __('users.send_invitation') }}</button>
                <button type="button" class="btn-secondary" wire:click="revokeSessions" wire:confirm="{{ __('users.confirm_revoke') }}">{{ __('users.revoke_sessions') }}</button>
                @if ($user->two_factor_confirmed_at)
                    <button type="button" class="btn-danger" wire:click="resetTwoFactor" wire:confirm="{{ __('users.confirm_2fa_reset') }}">{{ __('users.reset_2fa') }}</button>
                @endif
            </div>
        </section>
    @endif
</div>
