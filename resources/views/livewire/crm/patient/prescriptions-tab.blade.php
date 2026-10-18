@php use App\Domain\Clinical\PrescriptionService as Rx; @endphp
<div class="space-y-6">
    <div aria-live="polite">
        @if ($notice)<div class="rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ $notice }}</div>@endif
    </div>
    @if ($canCreate && $editing === null)
        <button type="button" class="btn-primary" wire:click="create">{{ __('patients.rx.new') }}</button>
    @endif

    @if ($editing !== null)
        <form wire:submit="save" class="panel space-y-5 p-5" data-preserve>
            <h2 class="text-base font-semibold">{{ $editing ? __('patients.rx.amend_title') : __('patients.rx.new') }}</h2>
            @error('form')<p class="rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad" role="alert">{{ $message }}</p>@enderror
            @if ($editing === 0)
                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <label for="rx-type" class="field-label">{{ __('crm.fields.type') }}</label>
                        <select id="rx-type" wire:model.live="type" class="input">
                            @foreach (\App\Models\Prescription::TYPES as $t)<option value="{{ $t }}">{{ __('clinical.rx.types.'.$t) }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label for="rx-record" class="field-label">{{ __('patients.rx.from_record') }}</label>
                        <div class="flex gap-2">
                            <select id="rx-record" wire:model="recordId" class="input">
                                <option value="">—</option>
                                @foreach ($records as $r)<option value="{{ $r->id }}">{{ $r->examined_at->timezone($tz)->format('d.m.Y') }} · {{ __('clinical.status.'.$r->status) }}</option>@endforeach
                            </select>
                            <button type="button" class="btn-secondary shrink-0" wire:click="copyFromRecord" @disabled(! $recordId)>{{ __('patients.rx.copy') }}</button>
                        </div>
                        <p class="field-hint">{{ __('patients.rx.copy_hint') }}</p>
                    </div>
                    @if (! $recordId)
                        <div>
                            <label for="rx-loc" class="field-label">{{ __('crm.fields.location') }}</label>
                            <select id="rx-loc" wire:model="locationId" class="input">
                                <option value="">—</option>
                                @foreach ($locations as $l)<option value="{{ $l->id }}">{{ $l->name() }}</option>@endforeach
                            </select>
                            @error('locationId')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    @endif
                </div>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full min-w-[20rem] text-sm">
                    <caption class="sr-only">{{ __('clinical.rx.title') }}</caption>
                    <thead><tr>
                        <th scope="col" class="w-12 py-2 text-left font-medium text-muted"><span class="sr-only">{{ __('patients.rx.eye') }}</span></th>
                        @foreach ($fields as $f)<th scope="col" class="px-1 py-2 text-left font-medium text-muted">{{ __('clinical.rx.'.$f) }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                    @foreach (['od', 'os'] as $eye)
                        <tr>
                            <th scope="row" class="py-1 pr-2 text-left font-semibold"><abbr title="{{ __('clinical.rx.'.$eye) }}" class="no-underline">{{ strtoupper($eye) }}</abbr><span class="sr-only"> – {{ __('clinical.rx.'.$eye) }}</span></th>
                            @foreach ($fields as $f)
                                <td class="px-1 py-1">
                                    <input type="text" inputmode="decimal" wire:model.blur="data.{{ $eye }}.{{ $f }}" class="input min-w-14 px-1.5 text-center tabular-nums"
                                           aria-label="{{ __('clinical.rx.'.$f) }} {{ strtoupper($eye) }}" @error("data.$eye.$f") aria-invalid="true" @enderror>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @foreach ($errors->get('data.*') as $messages)
                @foreach ($messages as $m)<p class="field-error">{{ $m }}</p>@endforeach
            @endforeach

            <div class="grid gap-4 md:grid-cols-3">
                @if ($type === 'glasses')
                    <x-admin.text model="data.pd" inputmode="decimal" :label="__('clinical.rx.pd')" />
                    <div>
                        <label for="rx-use" class="field-label">{{ __('clinical.rx.use') }}</label>
                        <select id="rx-use" wire:model="data.use" class="input">
                            <option value="">—</option>
                            @foreach (['distance', 'near', 'intermediate', 'progressive', 'bifocal'] as $u)<option value="{{ $u }}">{{ __('clinical.rx.uses.'.$u) }}</option>@endforeach
                        </select>
                    </div>
                @endif
                <x-admin.text model="validUntil" type="date" :label="__('clinical.rx.valid_until')" />
            </div>
            <div>
                <label for="rx-notes" class="field-label">{{ __('clinical.rx.notes') }}</label>
                <textarea id="rx-notes" wire:model="data.notes" rows="3" class="input"></textarea>
            </div>
            @if ($editing)
                <div>
                    <label for="rx-reason" class="field-label">{{ __('patients.rx.reason') }} <span class="text-bad" aria-hidden="true">*</span></label>
                    <textarea id="rx-reason" wire:model="reason" rows="2" class="input" required></textarea>
                </div>
            @endif
            <p class="text-xs text-muted">{{ __('patients.rx.no_automation') }}</p>
            <div class="flex gap-3">
                <button type="submit" class="btn-primary">{{ $editing ? __('patients.rx.save_version') : __('patients.rx.issue') }}</button>
                <button type="button" class="btn-secondary" wire:click="$set('editing', null)">{{ __('crm.actions.cancel') }}</button>
            </div>
        </form>
    @endif

    <ul class="space-y-3" role="list">
        @forelse ($prescriptions as $rx)
            <li class="panel p-4" wire:key="rx-{{ $rx->id }}">
                <div class="flex flex-wrap items-center gap-3">
                    <p class="flex-1 font-semibold text-heading">{{ __('clinical.rx.types.'.$rx->type) }} <span class="font-normal text-muted">· {{ __('clinical.rx.version', ['number' => $rx->version]) }}</span></p>
                    <span class="{{ $rx->status === 'active' ? 'badge-ok' : 'badge-neutral' }}">{{ __('clinical.rx.statuses.'.$rx->status) }}</span>
                </div>
                <p class="mt-1 text-xs text-muted">{{ __('clinical.rx.issued_on') }} {{ $rx->issued_on->format('d.m.Y') }} · {{ $rx->specialist?->display_name }} · {{ $rx->location?->name() }}
                    @if ($rx->valid_until) · {{ __('clinical.rx.valid_until') }} {{ $rx->valid_until->format('d.m.Y') }}@endif</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[26rem] text-sm">
                        <thead><tr>
                            <th scope="col" class="py-1 text-left font-medium text-muted"><span class="sr-only">{{ __('patients.rx.eye') }}</span></th>
                            @foreach (Rx::TYPE_FIELDS[$rx->type] as $f)<th scope="col" class="px-2 py-1 text-left font-medium text-muted">{{ __('clinical.rx.'.$f) }}</th>@endforeach
                        </tr></thead>
                        <tbody>
                        @foreach (['od', 'os'] as $eye)
                            <tr><th scope="row" class="py-1 text-left font-semibold">{{ strtoupper($eye) }}</th>
                                @foreach (Rx::TYPE_FIELDS[$rx->type] as $f)<td class="px-2 py-1 tabular-nums">{{ Rx::display($f, $rx->data[$eye][$f] ?? '') }}</td>@endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if (($rx->data['pd'] ?? '') !== '' || ($rx->data['use'] ?? '') !== '')
                    <p class="mt-2 text-sm">@if ($rx->data['pd'] !== ''){{ __('clinical.rx.pd') }}: {{ $rx->data['pd'] }}@endif @if (($rx->data['use'] ?? '') !== '') · {{ __('clinical.rx.uses.'.$rx->data['use']) }}@endif</p>
                @endif
                @if (($rx->data['notes'] ?? '') !== '')<p class="mt-2 whitespace-pre-line text-sm">{{ $rx->data['notes'] }}</p>@endif
                @if ($rx->amend_reason)<p class="mt-2 text-xs text-muted">{{ __('patients.rx.amended_because', ['reason' => $rx->amend_reason]) }}</p>@endif
                <div class="mt-3 flex flex-wrap gap-4 text-sm">
                    @foreach (config('optica.locales') as $l)
                        <a href="{{ route('crm.prescriptions.pdf', ['prescription' => $rx, 'lang' => $l]) }}" class="btn-link" target="_blank" rel="noopener">PDF {{ strtoupper($l) }}</a>
                    @endforeach
                    @if ($rx->status === 'active')
                        @can('prescriptions.amend')<button type="button" class="btn-link" wire:click="amend({{ $rx->id }})">{{ __('patients.rx.amend') }}</button>@endcan
                    @endif
                </div>
            </li>
        @empty
            <li class="panel p-8 text-center text-sm text-muted">{{ __('patients.rx.none') }}</li>
        @endforelse
    </ul>
</div>
