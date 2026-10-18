@php use App\Support\Money; @endphp
<div class="{{ $patientId ? '' : 'max-w-6xl' }}">
    <div class="flex flex-wrap items-end gap-3">
        @unless ($patientId)
            <div class="w-full max-w-xs">
                <label for="ml-q" class="field-label">{{ __('messages.log.recipient') }}</label>
                <input id="ml-q" type="search" wire:model.live.debounce.300ms="search" class="input">
            </div>
        @endunless
        <div>
            <label for="ml-status" class="field-label">{{ __('crm.fields.status') }}</label>
            <select id="ml-status" wire:model.live="status" class="input">
                <option value="">{{ __('crm.all') }}</option>
                @foreach (\App\Models\Message::STATUSES as $s)<option value="{{ $s }}">{{ __('messages.statuses.'.$s) }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="ml-ch" class="field-label">{{ __('messages.templates.channel') }}</label>
            <select id="ml-ch" wire:model.live="channel" class="input">
                <option value="">{{ __('crm.all') }}</option>
                @foreach (\App\Models\ChannelAccount::CHANNELS as $c)<option value="{{ $c }}">{{ __('messages.channels.'.$c) }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="ml-type" class="field-label">{{ __('messages.templates.type') }}</label>
            <select id="ml-type" wire:model.live="type" class="input">
                <option value="">{{ __('crm.all') }}</option>
                @foreach (array_keys(\App\Domain\Messaging\MessageTypes::TYPES) as $t)<option value="{{ $t }}">{{ __('messages.types.'.$t) }}</option>@endforeach
            </select>
        </div>
    </div>

    <ul class="panel mt-4 divide-y divide-line" role="list">
        @forelse ($messages as $m)
            <li class="px-4 py-3" wire:key="msg-{{ $m->id }}">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <button type="button" class="min-w-0 flex-1 text-left" wire:click="toggle({{ $m->id }})" aria-expanded="{{ $open === $m->id ? 'true' : 'false' }}">
                        <span class="block text-sm font-medium text-heading">{{ __('messages.types.'.$m->type) }} · {{ __('messages.channels.'.$m->channel) }}</span>
                        <span class="block text-xs text-muted">{{ $m->created_at->timezone($tz)->format('d.m.Y H:i') }} · {{ $m->recipient ?: '—' }}@if (! $patientId && $m->patient) · {{ $m->patient->fullName() }}@endif</span>
                    </button>
                    <span class="{{ match ($m->status) { 'delivered', 'sent', 'accepted' => 'badge-ok', 'failed' => 'badge-bad', 'uncertain', 'skipped' => 'badge-warn', 'pending', 'sending' => 'badge-info', default => 'badge-neutral' } }}"
                          title="{{ __('messages.status_hints.'.$m->status) !== 'messages.status_hints.'.$m->status ? __('messages.status_hints.'.$m->status) : '' }}">{{ __('messages.statuses.'.$m->status) }}</span>
                </div>
                @if ($m->status_reason && in_array($m->status, ['failed', 'uncertain', 'skipped', 'cancelled', 'pending'], true))
                    <p class="mt-1 text-xs text-muted">{{ __('messages.reasons.'.$m->status_reason) !== 'messages.reasons.'.$m->status_reason ? __('messages.reasons.'.$m->status_reason) : $m->status_reason }}</p>
                @endif
                @if ($open === $m->id)
                    <div class="mt-3 space-y-2 rounded-lg bg-paper p-3 text-sm">
                        @if ($m->subject)<p class="font-medium">{{ $m->subject }}</p>@endif
                        <p class="whitespace-pre-line" lang="{{ $m->locale }}">{{ $m->body ?: '—' }}</p>
                        <p class="text-xs text-muted">
                            @if ($m->segments) {{ trans_choice('messages.log.segments', $m->segments, ['count' => $m->segments]) }} · @endif
                            {{ __('messages.log.cost') }}: {{ __('messages.log.estimated') }} {{ Money::format($m->cost_estimated_cents) }} / {{ __('messages.log.reported') }} {{ Money::format($m->cost_reported_cents) }}
                            @if ($m->provider_message_id) · ID {{ $m->provider_message_id }}@endif
                        </p>
                        @if ($m->status === 'uncertain')<p class="text-xs text-warn">{{ __('messages.status_hints.uncertain') }}</p>@endif
                        @if ($m->events->isNotEmpty())
                            <p class="text-xs font-semibold">{{ __('messages.log.events') }}</p>
                            <ol class="text-xs text-muted" role="list">
                                @foreach ($m->events as $e)<li>{{ $e->created_at->timezone($tz)->format('d.m.Y H:i:s') }} · {{ $e->source }} · {{ $e->status }}@if ($e->detail) · {{ $e->detail }}@endif</li>@endforeach
                            </ol>
                        @endif
                        @can('communications.send')
                            <div class="flex flex-wrap gap-3 pt-1">
                                @if (in_array($m->status, ['failed', 'uncertain'], true) && $m->category !== 'marketing')
                                    <button type="button" class="btn-secondary" wire:click="retry({{ $m->id }})" wire:confirm="{{ __('messages.log.confirm_retry') }}">{{ __('messages.log.retry') }}</button>
                                @endif
                                @if ($m->status === 'pending')
                                    <button type="button" class="btn-link text-sm text-bad" wire:click="cancel({{ $m->id }})">{{ __('messages.log.cancel') }}</button>
                                @endif
                            </div>
                        @endcan
                    </div>
                @endif
            </li>
        @empty
            <li class="px-4 py-10 text-center text-sm text-muted">{{ __('messages.log.none') }}</li>
        @endforelse
    </ul>
    <div class="mt-4">{{ $messages->links() }}</div>
</div>
