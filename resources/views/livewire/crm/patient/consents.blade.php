<div class="space-y-6">
    <section class="panel p-5" aria-labelledby="mk-title">
        <h2 id="mk-title" class="text-base font-semibold">{{ __('patients.consents.marketing_title') }}</h2>
        <p class="mt-1 max-w-prose text-sm text-muted">{{ __('patients.consents.marketing_hint') }}</p>
        <ul class="mt-4 divide-y divide-line" role="list">
            @foreach ($marketing as $channel => $granted)
                <li class="flex flex-wrap items-center gap-3 py-3">
                    <span class="flex-1 text-sm font-medium">{{ __('patients.consents.channels.'.$channel) }}</span>
                    <span class="{{ $granted ? 'badge-ok' : 'badge-neutral' }}">{{ $granted ? __('patients.consents.granted') : __('patients.consents.not_granted') }}</span>
                    @can('patients.update')
                        @if ($granted)
                            <button type="button" class="btn-secondary" wire:click="setMarketing('{{ $channel }}', false)" wire:confirm="{{ __('patients.consents.confirm_withdraw') }}">{{ __('patients.consents.withdraw') }}</button>
                        @else
                            <button type="button" class="btn-secondary" wire:click="setMarketing('{{ $channel }}', true)" wire:confirm="{{ __('patients.consents.confirm_grant') }}">{{ __('patients.consents.record_grant') }}</button>
                        @endif
                    @endcan
                </li>
            @endforeach
        </ul>
    </section>

    <section class="panel overflow-x-auto" aria-labelledby="cs-title">
        <h2 id="cs-title" class="px-4 pt-4 text-base font-semibold">{{ __('patients.consents.history') }}</h2>
        <table class="table-base table-stack mt-2">
            <thead><tr>
                <th scope="col">{{ __('patients.consents.date') }}</th>
                <th scope="col">{{ __('patients.consents.purpose') }}</th>
                <th scope="col">{{ __('patients.consents.decision') }}</th>
                <th scope="col">{{ __('patients.consents.source') }}</th>
                <th scope="col">{{ __('patients.consents.version') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($consents as $c)
                <tr wire:key="c-{{ $c->id }}">
                    <td class="whitespace-nowrap">{{ $c->given_at->timezone($tz)->format('d.m.Y H:i') }}</td>
                    <td data-label="{{ __('patients.consents.purpose') }}">{{ __('patients.consents.purposes.'.$c->purpose) }}@if ($c->channel) · {{ __('patients.consents.channels.'.$c->channel) }}@endif</td>
                    <td data-label="{{ __('patients.consents.decision') }}"><span class="{{ $c->granted ? 'badge-ok' : 'badge-neutral' }}">{{ $c->granted ? __('patients.consents.granted') : __('patients.consents.refused') }}</span></td>
                    <td data-label="{{ __('patients.consents.source') }}">{{ __('patients.consents.sources.'.$c->source) }}</td>
                    <td data-label="{{ __('patients.consents.version') }}">{{ $c->text_version }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-8 text-center text-muted">{{ __('crm.empty_list') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
</div>
