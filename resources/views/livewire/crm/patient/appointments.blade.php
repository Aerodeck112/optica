<div class="space-y-6">
    <section class="panel overflow-x-auto" aria-labelledby="appt-title">
        <h2 id="appt-title" class="px-4 pt-4 text-base font-semibold">{{ __('patients.tabs.appointments') }}</h2>
        <table class="table-base table-stack mt-2">
            <thead><tr>
                <th scope="col">{{ __('crm.fields.time') }}</th>
                <th scope="col">{{ __('crm.fields.service') }}</th>
                <th scope="col">{{ __('crm.fields.specialist') }}</th>
                <th scope="col">{{ __('crm.fields.location') }}</th>
                <th scope="col">{{ __('crm.fields.status') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($appointments as $a)
                <tr wire:key="a-{{ $a->id }}">
                    <td class="whitespace-nowrap">{{ $a->starts_at->timezone($tz)->format('d.m.Y H:i') }}</td>
                    <td data-label="{{ __('crm.fields.service') }}">{{ $a->service?->name() ?? '—' }}</td>
                    <td data-label="{{ __('crm.fields.specialist') }}">{{ $a->specialist?->display_name }}</td>
                    <td data-label="{{ __('crm.fields.location') }}">{{ $a->location?->name() }}</td>
                    <td data-label="{{ __('crm.fields.status') }}"><x-appointment-status :status="$a->status" /></td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-8 text-center text-muted">{{ __('crm.empty_list') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>

    <section class="panel overflow-x-auto" aria-labelledby="visit-title">
        <h2 id="visit-title" class="px-4 pt-4 text-base font-semibold">{{ __('patients.visits') }}</h2>
        <p class="px-4 text-xs text-muted">{{ __('patients.visits_hint') }}</p>
        <table class="table-base table-stack mt-2">
            <thead><tr>
                <th scope="col">{{ __('crm.fields.time') }}</th>
                <th scope="col">{{ __('crm.fields.service') }}</th>
                <th scope="col">{{ __('crm.fields.specialist') }}</th>
                <th scope="col">{{ __('crm.fields.location') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($visits as $v)
                <tr wire:key="v-{{ $v->id }}">
                    <td class="whitespace-nowrap">{{ $v->started_at->timezone($tz)->format('d.m.Y H:i') }}</td>
                    <td data-label="{{ __('crm.fields.service') }}">{{ $v->service?->name() ?? '—' }}</td>
                    <td data-label="{{ __('crm.fields.specialist') }}">{{ $v->specialist?->display_name }}</td>
                    <td data-label="{{ __('crm.fields.location') }}">{{ $v->location?->name() }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-8 text-center text-muted">{{ __('crm.empty_list') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
</div>
