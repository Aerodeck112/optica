@php use App\Support\Money; @endphp
<div class="max-w-6xl">
    <p class="max-w-prose text-sm text-muted">{{ __('messages.campaigns.intro') }}</p>
    <div aria-live="polite">
        @if ($notice)<div class="mt-4 rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ $notice }}</div>@endif
        @if ($failure)<div class="mt-4 rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad" role="alert">{{ $failure }}</div>@endif
    </div>
    @can('campaigns.manage')
        <div class="mt-4 flex"><button type="button" class="btn-primary ml-auto" wire:click="create">{{ __('messages.campaigns.new') }}</button></div>
    @endcan

    @if ($editing !== null)
        <form wire:submit="save" class="panel mt-6 space-y-6 p-5" data-preserve>
            <div class="grid gap-5 md:grid-cols-3">
                <x-admin.text model="form.name" :label="__('messages.campaigns.name')" required :disabled="! $editable" />
                <div>
                    <label for="cp-ch" class="field-label">{{ __('messages.campaigns.channel') }}</label>
                    <select id="cp-ch" wire:model.live="form.channel" class="input" @disabled(! $editable)>
                        @foreach (\App\Models\ChannelAccount::CHANNELS as $c)@if ($c !== 'whatsapp')<option value="{{ $c }}">{{ __('messages.channels.'.$c) }}</option>@endif @endforeach
                    </select>
                </div>
                <x-admin.text model="form.scheduled_at" type="datetime-local" :label="__('messages.campaigns.scheduled_at')" :disabled="! $editable" />
            </div>
            <div class="grid gap-5 md:grid-cols-2">
                <fieldset>
                    <legend class="field-label">{{ __('messages.campaigns.locales') }}</legend>
                    <div class="flex flex-wrap gap-4">
                        @foreach (config('optica.locales') as $l)
                            <label class="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" value="{{ $l }}" wire:model.live="form.locales" class="h-5 w-5 rounded border-line text-ink focus:ring-ink" @disabled(! $editable)> {{ __('crm.languages.'.$l) }}</label>
                        @endforeach
                    </div>
                    @error('form.locales')<p class="field-error">{{ $message }}</p>@enderror
                </fieldset>
                <fieldset>
                    <legend class="field-label">{{ __('messages.campaigns.locations') }}</legend>
                    <div class="flex flex-wrap gap-x-4">
                        @foreach ($locations as $l)
                            <label class="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" value="{{ $l->id }}" wire:model.live="form.location_ids" class="h-5 w-5 rounded border-line text-ink focus:ring-ink" @disabled(! $editable)> {{ $l->name() }}</label>
                        @endforeach
                    </div>
                    <p class="field-hint">{{ __('messages.campaigns.locations_hint') }}</p>
                </fieldset>
            </div>
            <p class="rounded-lg bg-mist/60 px-4 py-3 text-sm text-ink">{{ __('messages.campaigns.recipients') }}: <strong>{{ $recipientCount }}</strong></p>

            <div class="grid gap-5 md:grid-cols-2">
                @foreach ($form['locales'] as $l)
                    <fieldset class="space-y-3" wire:key="cp-content-{{ $l }}">
                        <legend class="field-label">{{ __('messages.campaigns.content') }} – {{ __('crm.languages.'.$l) }}</legend>
                        @if ($form['channel'] === 'email')
                            <x-admin.text :model="'form.content.'.$l.'.subject'" :label="__('messages.templates.subject')" :disabled="! $editable" />
                        @endif
                        <div>
                            <label for="cp-body-{{ $l }}" class="sr-only">{{ __('messages.templates.body') }}</label>
                            <textarea id="cp-body-{{ $l }}" wire:model.live.debounce.400ms="form.content.{{ $l }}.body" rows="6" lang="{{ $l }}" class="input" @disabled(! $editable)></textarea>
                            @error('form.content.'.$l.'.body')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    </fieldset>
                @endforeach
            </div>
            <p class="text-xs text-muted">{{ __('messages.campaigns.unsubscribe_hint') }} {{ __('messages.templates.variables') }}: <code>{patient_name}</code> <code>{booking_link}</code> <code>{unsubscribe_link}</code></p>
            @if ($sms)
                <x-admin.toggle model="form.ascii_only" :label="__('messages.templates.ascii_only')" />
                <p class="text-xs text-muted">{{ __('messages.templates.sms_info', ['length' => $sms['length'], 'encoding' => $sms['encoding'], 'segments' => $sms['segments'], 'per' => $sms['per_segment']]) }}</p>
                @if ($sms['non_gsm'])<p class="text-xs text-warn">{{ __('messages.templates.sms_diacritics', ['chars' => implode(' ', $sms['non_gsm'])]) }}</p>@endif
            @endif

            <div class="flex flex-wrap items-end gap-3 border-t border-line pt-5">
                @if ($editable)
                    <button type="submit" class="btn-secondary">{{ __('messages.campaigns.save') }}</button>
                    @can('campaigns.send')<button type="button" class="btn-primary" wire:click="schedule">{{ __('messages.campaigns.schedule') }}</button>@endcan
                @endif
                <button type="button" class="btn-link text-sm" wire:click="cancel">{{ __('crm.actions.back_to_list') }}</button>
                @can('campaigns.send')
                    <div class="ml-auto flex flex-wrap items-end gap-2">
                        <x-admin.text model="testTo" :label="__('messages.campaigns.test_to')" autocomplete="off" />
                        <button type="button" class="btn-secondary" wire:click="sendTest">{{ __('messages.campaigns.test') }}</button>
                    </div>
                @endcan
            </div>

            @if ($campaign && $stats->isNotEmpty())
                <section class="border-t border-line pt-5" aria-labelledby="cp-stats">
                    <h2 id="cp-stats" class="text-base font-semibold">{{ __('messages.campaigns.stats') }}</h2>
                    <dl class="mt-3 flex flex-wrap gap-3 text-sm">
                        @foreach ($stats as $status => $n)<div class="rounded-lg bg-paper px-3 py-2"><dt class="text-xs text-muted">{{ __('messages.statuses.'.$status) }}</dt><dd class="font-semibold">{{ $n }}</dd></div>@endforeach
                    </dl>
                    <p class="mt-2 text-xs text-muted">{{ __('messages.log.cost') }}: {{ __('messages.log.estimated') }} {{ Money::format((int) $costs?->estimated) }} / {{ __('messages.log.reported') }} {{ Money::format($costs?->reported !== null ? (int) $costs->reported : null) }}</p>
                    <p class="mt-1 text-xs text-muted">{{ __('messages.campaigns.opens_note') }}</p>
                </section>
            @endif
        </form>
    @endif

    <div class="panel mt-6 overflow-x-auto">
        <table class="table-base table-stack">
            <thead><tr>
                <th scope="col">{{ __('messages.campaigns.name') }}</th>
                <th scope="col">{{ __('messages.campaigns.channel') }}</th>
                <th scope="col">{{ __('crm.fields.status') }}</th>
                <th scope="col">{{ __('messages.campaigns.scheduled_at') }}</th>
                <th scope="col"><span class="sr-only">{{ __('crm.actions.actions') }}</span></th>
            </tr></thead>
            <tbody>
            @forelse ($campaigns as $c)
                <tr wire:key="cp-{{ $c->id }}">
                    <td><button type="button" class="btn-link text-left" wire:click="edit({{ $c->id }})">{{ $c->name }}</button><span class="block text-xs text-muted">{{ $c->messages_count }} {{ __('messages.campaigns.messages') }}</span></td>
                    <td data-label="{{ __('messages.campaigns.channel') }}">{{ __('messages.channels.'.$c->channel) }}</td>
                    <td data-label="{{ __('crm.fields.status') }}"><span class="{{ match ($c->status) { 'sending' => 'badge-info', 'done' => 'badge-ok', 'paused', 'scheduled' => 'badge-warn', default => 'badge-neutral' } }}">{{ __('messages.campaigns.statuses.'.$c->status) }}</span></td>
                    <td class="whitespace-nowrap" data-label="{{ __('messages.campaigns.scheduled_at') }}">{{ $c->scheduled_at?->timezone(config('optica.clinic_timezone'))->format('d.m.Y H:i') ?? '—' }}</td>
                    <td class="cell-actions whitespace-nowrap text-right">
                        @can('campaigns.send')
                            @if ($c->status === 'sending')<button type="button" class="btn-link text-sm" wire:click="setStatus({{ $c->id }}, 'paused')">{{ __('messages.campaigns.pause') }}</button>@endif
                            @if ($c->status === 'paused')<button type="button" class="btn-link text-sm" wire:click="setStatus({{ $c->id }}, 'sending')">{{ __('messages.campaigns.resume') }}</button>@endif
                            @if ($c->status === 'scheduled')<button type="button" class="btn-link text-sm" wire:click="setStatus({{ $c->id }}, 'draft')">{{ __('messages.campaigns.unschedule') }}</button>@endif
                            @if (in_array($c->status, ['scheduled', 'sending', 'paused'], true))
                                <button type="button" class="btn-link ml-3 text-sm text-bad" wire:click="setStatus({{ $c->id }}, 'stopped')" wire:confirm="{{ __('messages.campaigns.confirm_stop') }}">{{ __('messages.campaigns.stop') }}</button>
                            @endif
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-10 text-center text-muted">{{ __('messages.campaigns.none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
