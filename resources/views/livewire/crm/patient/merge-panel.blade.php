<section class="panel p-4" aria-labelledby="merge-title">
    <h2 id="merge-title" class="text-sm font-semibold">{{ __('patients.merge.title') }}</h2>
    <p class="mt-1 text-xs text-muted">{{ __('patients.merge.hint') }}</p>
    @if (! $duplicate)
        @if ($suggestions->isNotEmpty())
            <p class="mt-3 text-xs font-medium text-warn">{{ __('patients.merge.suggestions') }}</p>
            <ul class="mt-1 space-y-1 text-sm" role="list">
                @foreach ($suggestions as $s)
                    <li><button type="button" class="btn-link text-left" wire:click="choose({{ $s->id }})">{{ $s->fullName() }}</button> <span class="text-xs text-muted">{{ $s->code }}</span></li>
                @endforeach
            </ul>
        @endif
        <label for="merge-q" class="field-label mt-3">{{ __('patients.merge.search') }}</label>
        <input id="merge-q" type="search" class="input" wire:model.live.debounce.300ms="query" autocomplete="off">
        @if ($results->isNotEmpty())
        <ul class="mt-2 divide-y divide-line rounded-lg ring-1 ring-line" role="list">
            @foreach ($results as $r)
                <li><button type="button" class="block min-h-11 w-full px-3 py-2 text-left text-sm hover:bg-mist" wire:click="choose({{ $r->id }})">{{ $r->fullName() }} <span class="text-muted">· {{ $r->code }}</span></button></li>
            @endforeach
        </ul>
        @endif
    @else
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-xs">
                <thead><tr>
                    <th scope="col" class="py-1 text-left font-medium text-muted"><span class="sr-only">{{ __('patients.merge.field') }}</span></th>
                    <th scope="col" class="px-1 py-1 text-left font-semibold text-ok">{{ __('patients.merge.keep') }}</th>
                    <th scope="col" class="px-1 py-1 text-left font-semibold text-bad">{{ __('patients.merge.remove') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-line">
                    @foreach ([
                        __('patients.fields.code') => [$current->code, $duplicate->code],
                        __('crm.fields.name') => [$current->fullName(), $duplicate->fullName()],
                        __('patients.fields.birth_date') => [$current->birth_date?->format('d.m.Y'), $duplicate->birth_date?->format('d.m.Y')],
                        __('crm.fields.phone') => [$current->phone, $duplicate->phone],
                        __('crm.fields.email') => [$current->email, $duplicate->email],
                        __('patients.tabs.appointments') => [$current->appointments_count, $duplicate->appointments_count],
                        __('patients.tabs.documents') => [$current->documents_count, $duplicate->documents_count],
                    ] as $label => [$a, $b])
                        <tr><th scope="row" class="py-1 pr-1 text-left font-medium text-muted">{{ $label }}</th><td class="px-1 py-1">{{ $a ?? '—' }}</td><td class="px-1 py-1">{{ $b ?? '—' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-body">{{ __('patients.merge.explain') }}</p>
        <label class="mt-3 flex items-start gap-2 text-sm">
            <input type="checkbox" wire:model="confirmed" class="mt-0.5 h-5 w-5 rounded border-line text-ink focus:ring-ink">
            <span>{{ __('patients.merge.confirm_label') }}</span>
        </label>
        @error('confirmed')<p class="field-error">{{ $message }}</p>@enderror
        <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" class="btn-danger" wire:click="merge">{{ __('patients.merge.do') }}</button>
            <button type="button" class="btn-secondary" wire:click="cancel">{{ __('crm.actions.cancel') }}</button>
        </div>
    @endif
</section>
