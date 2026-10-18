<form wire:submit="save" class="max-w-6xl space-y-6">
    <p class="max-w-prose text-sm text-muted">{{ __('messages.rules.intro') }}</p>
    <div aria-live="polite">
        @if ($notice)<div class="rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ $notice }}</div>@endif
    </div>
    <div class="flex flex-wrap items-end gap-4">
        <div>
            <label for="r-loc" class="field-label">{{ __('messages.templates.location') }}</label>
            <select id="r-loc" wire:model.live="location" class="input">
                <option value="">{{ __('messages.templates.general') }}</option>
                @foreach ($locations as $l)<option value="{{ $l->id }}">{{ $l->name() }}</option>@endforeach
            </select>
        </div>
        <p class="flex flex-wrap gap-2 text-sm">
            @foreach ($overview as $channel => $state)
                <span class="{{ $state === 'ready' ? 'badge-ok' : 'badge-neutral' }}">{{ __('messages.channels.'.$channel) }}: {{ __('messages.accounts.state.'.$state) }}</span>
            @endforeach
        </p>
    </div>

    <div class="panel overflow-x-auto">
        <table class="table-base table-stack">
            <thead><tr>
                <th scope="col">{{ __('messages.templates.type') }}</th>
                <th scope="col">{{ __('messages.rules.enabled') }}</th>
                <th scope="col">{{ __('messages.rules.channels') }}</th>
                <th scope="col">{{ __('messages.rules.offset') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($rules as $type => $row)
                <tr wire:key="rule-{{ $type }}-{{ $location }}">
                    <td class="font-medium">{{ __('messages.types.'.$type) }}
                        @if ($location && $row['exists'])<button type="button" class="btn-link mt-1 block text-xs text-muted" wire:click="removeOverride('{{ $type }}')">{{ __('messages.rules.use_general') }}</button>@endif</td>
                    <td data-label="{{ __('messages.rules.enabled') }}">
                        <input type="checkbox" wire:model="rules.{{ $type }}.enabled" class="h-5 w-5 rounded border-line text-ink focus:ring-ink" aria-label="{{ __('messages.rules.enabled') }}: {{ __('messages.types.'.$type) }}">
                    </td>
                    <td data-label="{{ __('messages.rules.channels') }}">
                        <div class="flex flex-wrap gap-2">
                            @foreach (['c1', 'c2', 'c3'] as $i => $slot)
                                <select wire:model="rules.{{ $type }}.{{ $slot }}" class="input w-36" aria-label="{{ __('messages.rules.channel_n', ['n' => $i + 1]) }}">
                                    <option value="">{{ __('messages.rules.none_channel') }}</option>
                                    @foreach (\App\Models\ChannelAccount::CHANNELS as $c)<option value="{{ $c }}">{{ __('messages.channels.'.$c) }}</option>@endforeach
                                </select>
                            @endforeach
                        </div>
                    </td>
                    <td data-label="{{ __('messages.rules.offset') }}">
                        @if ($type === 'appointment_reminder')
                            <input type="number" min="1" max="168" wire:model="rules.{{ $type }}.offset" class="input w-24" aria-label="{{ __('messages.rules.offset') }}">
                            @error('rules.appointment_reminder.offset')<p class="field-error">{{ $message }}</p>@enderror
                        @else — @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @unless ($location)
        <fieldset class="panel grid gap-5 p-5 md:grid-cols-3">
            <legend class="sr-only">{{ __('messages.rules.quiet_hours') }}</legend>
            <div class="md:col-span-3">
                <p class="text-base font-semibold">{{ __('messages.rules.quiet_hours') }}</p>
                <p class="text-xs text-muted">{{ __('messages.rules.quiet_hint') }}</p>
            </div>
            <x-admin.text model="quietStart" type="time" :label="__('messages.rules.quiet_start')" />
            <x-admin.text model="quietEnd" type="time" :label="__('messages.rules.quiet_end')" />
            <x-admin.text model="marketingLimit" type="number" min="0" max="14" :label="__('messages.rules.marketing_limit')" />
        </fieldset>
    @endunless
    <button type="submit" class="btn-primary">{{ __('crm.actions.save') }}</button>
</form>
