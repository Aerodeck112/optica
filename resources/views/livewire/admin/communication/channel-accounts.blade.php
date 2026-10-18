<div class="max-w-6xl">
    <p class="max-w-prose text-sm text-muted">{{ __('messages.accounts.intro') }}</p>
    <div aria-live="polite">
        @if ($notice)<div class="mt-4 rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ $notice }}</div>@endif
        @if ($failure)<div class="mt-4 rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad" role="alert">{{ $failure }}</div>@endif
    </div>

    <div class="mt-6 grid gap-4 md:grid-cols-3">
        @foreach (\App\Models\ChannelAccount::CHANNELS as $channel)
            <section class="panel p-4" aria-labelledby="ch-{{ $channel }}">
                <div class="flex items-center gap-2">
                    <h2 id="ch-{{ $channel }}" class="flex-1 text-base font-semibold">{{ __('messages.channels.'.$channel) }}</h2>
                    <span class="{{ match ($overview[$channel]) { 'ready' => 'badge-ok', 'incomplete' => 'badge-warn', default => 'badge-neutral' } }}">{{ __('messages.accounts.state.'.$overview[$channel]) }}</span>
                </div>
                <ul class="mt-3 space-y-2 text-sm" role="list">
                    @foreach ($accounts->where('channel', $channel) as $a)
                        <li class="rounded-lg bg-paper p-3" wire:key="acc-{{ $a->id }}">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="flex-1 font-medium">{{ $a->name }}</span>
                                @unless ($a->is_active)<span class="badge-neutral">{{ __('crm.state.inactive') }}</span>@endunless
                            </div>
                            <p class="text-xs text-muted">{{ __('messages.drivers.'.$a->driver) }} · {{ $a->location?->name() ?? __('messages.accounts.all_locations') }}</p>
                            @if ($missing[$a->id])<p class="mt-1 text-xs text-warn">{{ __('messages.accounts.missing', ['fields' => implode(', ', array_map(fn ($k) => $k === 'sender' ? __('messages.accounts.sender') : __('messages.accounts.fields.'.$k), $missing[$a->id]))]) }}</p>@endif
                            @if ($a->last_tested_at)<p class="mt-1 text-xs text-muted">{{ __('messages.accounts.last_test', ['date' => $a->last_tested_at->timezone(config('optica.clinic_timezone'))->format('d.m.Y H:i'), 'result' => $a->last_test_result]) }}</p>@endif
                            <div class="mt-2 flex flex-wrap gap-3">
                                <button type="button" class="btn-link text-xs" wire:click="edit({{ $a->id }})">{{ __('crm.actions.edit') }}</button>
                                <button type="button" class="btn-link text-xs" wire:click="startTest({{ $a->id }})">{{ __('messages.accounts.test') }}</button>
                            </div>
                            @if ($testing === $a->id)
                                <form wire:submit="sendTest" class="mt-3 space-y-2">
                                    @if ($a->channel === 'whatsapp')
                                        <p class="text-xs text-muted">{{ __('messages.accounts.whatsapp_check_hint') }}</p>
                                    @else
                                        <x-admin.text model="testTo" :label="__('messages.accounts.test_to')" :hint="__('messages.accounts.test_hint')" required autocomplete="off" />
                                    @endif
                                    <div class="flex gap-2">
                                        <button type="submit" class="btn-primary">{{ __('messages.accounts.test') }}</button>
                                        <button type="button" class="btn-secondary" wire:click="cancel">{{ __('crm.actions.cancel') }}</button>
                                    </div>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <button type="button" class="btn-secondary mt-3 w-full" wire:click="create('{{ $channel }}')">{{ __('messages.accounts.add') }}</button>
            </section>
        @endforeach
    </div>

    @if ($editing !== null)
        <form wire:submit="save" class="panel mt-8 space-y-5 p-5" autocomplete="off">
            <h2 class="text-base font-semibold">{{ $editing ? __('messages.accounts.edit') : __('messages.accounts.add') }} – {{ __('messages.channels.'.$form['channel']) }}</h2>
            <div class="grid gap-5 md:grid-cols-2">
                <x-admin.text model="form.name" :label="__('messages.accounts.name')" required />
                <div>
                    <label for="acc-driver" class="field-label">{{ __('messages.accounts.driver') }}</label>
                    <select id="acc-driver" wire:model.live="form.driver" class="input">
                        @foreach (\App\Models\ChannelAccount::DRIVERS[$form['channel']] as $d)<option value="{{ $d }}">{{ __('messages.drivers.'.$d) }}</option>@endforeach
                    </select>
                </div>
                <x-admin.text model="form.sender" :label="__('messages.accounts.sender')" :hint="__('messages.accounts.sender_hints.'.$form['channel'])" />
                <div>
                    <label for="acc-loc" class="field-label">{{ __('messages.accounts.location') }}</label>
                    <select id="acc-loc" wire:model="form.location_id" class="input">
                        <option value="">{{ __('messages.accounts.all_locations') }}</option>
                        @foreach ($locations as $l)<option value="{{ $l->id }}">{{ $l->name() }}</option>@endforeach
                    </select>
                </div>
                <x-admin.text model="form.per_minute_limit" type="number" min="1" :label="__('messages.accounts.per_minute')" />
                <x-admin.text model="form.unit_cost" inputmode="decimal" :label="__('messages.accounts.unit_cost')" :hint="__('messages.accounts.unit_cost_hint')" />
            </div>
            <fieldset class="space-y-4 border-t border-line pt-5">
                <legend class="text-base font-semibold">{{ __('messages.accounts.credentials') }}</legend>
                <p class="text-xs text-muted">{{ __('messages.accounts.credential_keep') }}</p>
                <div class="grid gap-5 md:grid-cols-2">
                    @foreach (\App\Livewire\Admin\Communication\ChannelAccounts::fields($form['driver']) as $key)
                        @php($secret = in_array($key, ['password', 'auth_token', 'access_token', 'app_secret'], true))
                        <div>
                            <label for="sec-{{ $key }}" class="field-label">{{ __('messages.accounts.fields.'.$key) }}
                                @if (in_array($key, $saved, true))<span class="badge-ok ml-1">{{ __('messages.accounts.credential_saved') }}</span>@endif</label>
                            <input id="sec-{{ $key }}" type="{{ $secret ? 'password' : 'text' }}" wire:model="secrets.{{ $key }}" class="input" autocomplete="new-password" spellcheck="false">
                            @error('secrets.'.$key)<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
                @if ($editing && in_array($form['driver'], ['twilio', 'whatsapp_cloud'], true))
                    <div class="rounded-lg bg-paper p-3 text-sm">
                        <p class="font-medium">{{ __('messages.accounts.webhook') }}</p>
                        <p class="mt-1 break-all font-mono text-xs">{{ $form['driver'] === 'twilio' ? route('webhooks.twilio', $editing) : route('webhooks.whatsapp', $editing) }}</p>
                        <p class="mt-1 text-xs text-muted">{{ __('messages.accounts.webhook_hint') }}</p>
                    </div>
                @endif
            </fieldset>
            <x-admin.toggle model="form.is_active" :label="__('messages.accounts.active')" />
            <div class="flex flex-wrap gap-3">
                <button type="submit" class="btn-primary">{{ __('crm.actions.save') }}</button>
                <button type="button" class="btn-secondary" wire:click="cancel">{{ __('crm.actions.cancel') }}</button>
                @if ($editing)
                    <button type="button" class="btn-danger ml-auto" wire:click="delete({{ $editing }})" wire:confirm="{{ __('messages.accounts.confirm_delete') }}">{{ __('messages.accounts.delete') }}</button>
                @endif
            </div>
        </form>
    @endif
</div>
