<div class="max-w-6xl">
    <p class="max-w-prose text-sm text-muted">{{ __('messages.templates.intro') }}</p>
    <div aria-live="polite">
        @if ($notice)<div class="mt-4 rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ $notice }}</div>@endif
    </div>
    <div class="mt-4 flex flex-wrap items-end gap-4">
        <div>
            <label for="ft" class="field-label">{{ __('messages.templates.type') }}</label>
            <select id="ft" wire:model.live="filterType" class="input">
                <option value="">{{ __('crm.all') }}</option>
                @foreach (\App\Domain\Messaging\MessageTypes::operational() as $t)<option value="{{ $t }}">{{ __('messages.types.'.$t) }}</option>@endforeach
            </select>
        </div>
        <button type="button" class="btn-primary ml-auto" wire:click="create">{{ __('messages.templates.new') }}</button>
    </div>

    @if ($editing !== null)
        <form wire:submit="save" class="panel mt-6 grid gap-6 p-5 lg:grid-cols-[minmax(0,1fr)_20rem]" data-preserve>
            <div class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="t-type" class="field-label">{{ __('messages.templates.type') }}</label>
                        <select id="t-type" wire:model.live="form.type" class="input">
                            @foreach (\App\Domain\Messaging\MessageTypes::operational() as $t)<option value="{{ $t }}">{{ __('messages.types.'.$t) }}</option>@endforeach
                        </select>
                        @error('form.type')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="t-ch" class="field-label">{{ __('messages.templates.channel') }}</label>
                        <select id="t-ch" wire:model.live="form.channel" class="input">
                            @foreach (\App\Models\ChannelAccount::CHANNELS as $c)<option value="{{ $c }}">{{ __('messages.channels.'.$c) }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label for="t-loc" class="field-label">{{ __('messages.templates.locale') }}</label>
                        <select id="t-loc" wire:model.live="form.locale" class="input">
                            @foreach (config('optica.locales') as $l)<option value="{{ $l }}">{{ __('crm.languages.'.$l) }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label for="t-location" class="field-label">{{ __('messages.templates.location') }}</label>
                        <select id="t-location" wire:model="form.location_id" class="input">
                            <option value="">{{ __('messages.templates.general') }}</option>
                            @foreach ($locations as $l)<option value="{{ $l->id }}">{{ $l->name() }}</option>@endforeach
                        </select>
                    </div>
                </div>
                @if ($form['channel'] === 'email')
                    <x-admin.text model="form.subject" :label="__('messages.templates.subject')" required maxlength="200" />
                @endif
                <div>
                    <label for="t-body" class="field-label">{{ __('messages.templates.body') }}</label>
                    <textarea id="t-body" wire:model.live.debounce.400ms="form.body" rows="7" lang="{{ $form['locale'] }}" class="input font-mono text-sm" @error('form.body') aria-invalid="true" @enderror></textarea>
                    @error('form.body')<p class="field-error">{{ $message }}</p>@enderror
                    @if ($invalid)<p class="field-error">{{ __('messages.templates.invalid_variables', ['list' => implode(', ', $invalid)]) }}</p>@endif
                </div>
                @if ($form['channel'] === 'sms')
                    <x-admin.toggle model="form.ascii_only" :label="__('messages.templates.ascii_only')" />
                @endif
                @if ($form['channel'] === 'whatsapp')
                    <p class="rounded-lg bg-mist/60 px-4 py-3 text-sm text-ink">{{ __('messages.templates.whatsapp_hint') }}</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-admin.text model="form.provider_template" :label="__('messages.templates.provider_template')" />
                        <div>
                            <label for="t-ps" class="field-label">{{ __('messages.templates.provider_status') }}</label>
                            <select id="t-ps" wire:model="form.provider_status" class="input">
                                @foreach (array_diff(\App\Models\MessageTemplate::PROVIDER_STATUSES, ['not_required']) as $s)<option value="{{ $s }}">{{ __('messages.templates.provider_statuses.'.$s) }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                @endif
                <x-admin.toggle model="form.is_active" :label="__('messages.templates.active')" />
                <div class="flex gap-3">
                    <button type="submit" class="btn-primary">{{ __('crm.actions.save') }}</button>
                    <button type="button" class="btn-secondary" wire:click="cancel">{{ __('crm.actions.cancel') }}</button>
                </div>
            </div>
            <aside class="space-y-4 text-sm">
                <div>
                    <p class="font-semibold">{{ __('messages.templates.variables') }}</p>
                    <ul class="mt-2 flex flex-wrap gap-1.5" role="list">
                        @foreach ($variables as $v)<li><code class="rounded bg-paper px-1.5 py-0.5 text-xs ring-1 ring-line">{{ '{'.$v.'}' }}</code></li>@endforeach
                    </ul>
                </div>
                @if ($preview !== null)
                    <div>
                        <p class="font-semibold">{{ __('messages.templates.preview') }}</p>
                        <div class="mt-2 whitespace-pre-line rounded-lg bg-paper p-3 ring-1 ring-line" lang="{{ $form['locale'] }}">{{ $preview }}</div>
                        @if ($sms)
                            <p class="mt-2 text-xs text-muted">{{ __('messages.templates.sms_info', ['length' => $sms['length'], 'encoding' => $sms['encoding'], 'segments' => $sms['segments'], 'per' => $sms['per_segment']]) }}</p>
                            @if ($sms['non_gsm'])<p class="mt-1 text-xs text-warn">{{ __('messages.templates.sms_diacritics', ['chars' => implode(' ', $sms['non_gsm'])]) }}</p>@endif
                        @endif
                    </div>
                @endif
            </aside>
        </form>
    @endif

    <div class="panel mt-6 overflow-x-auto">
        <table class="table-base table-stack">
            <thead><tr>
                <th scope="col">{{ __('messages.templates.type') }}</th>
                <th scope="col">{{ __('messages.templates.channel') }}</th>
                <th scope="col">{{ __('messages.templates.locale') }}</th>
                <th scope="col">{{ __('crm.fields.status') }}</th>
                <th scope="col"><span class="sr-only">{{ __('crm.actions.actions') }}</span></th>
            </tr></thead>
            <tbody>
            @forelse ($templates as $t)
                <tr wire:key="tpl-{{ $t->id }}">
                    <td>{{ __('messages.types.'.$t->type) }}<span class="block text-xs text-muted">{{ $t->location?->name() ?? __('messages.templates.general') }}</span></td>
                    <td data-label="{{ __('messages.templates.channel') }}">{{ __('messages.channels.'.$t->channel) }}</td>
                    <td data-label="{{ __('messages.templates.locale') }}">{{ strtoupper($t->locale) }}</td>
                    <td data-label="{{ __('crm.fields.status') }}">
                        @if (! $t->is_active)<span class="badge-neutral">{{ __('crm.state.inactive') }}</span>
                        @elseif ($t->channel === 'whatsapp')<span class="{{ $t->provider_status === 'approved' ? 'badge-ok' : 'badge-warn' }}">{{ __('messages.templates.provider_statuses.'.$t->provider_status) }}</span>
                        @else<span class="badge-ok">{{ __('messages.templates.active') }}</span>@endif
                    </td>
                    <td class="cell-actions text-right"><button type="button" class="btn-link text-sm" wire:click="edit({{ $t->id }})">{{ __('crm.actions.edit') }}</button></td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-10 text-center text-muted">{{ __('messages.templates.none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
