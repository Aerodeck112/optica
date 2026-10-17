<x-layouts.crm :title="__('crm.dashboard.title')">
    <div class="max-w-5xl">
        <p class="text-sm text-muted">{{ now(config('optica.clinic_timezone'))->locale(app()->getLocale())->isoFormat('dddd, D MMMM YYYY') }}</p>
        <h2 class="mt-1 text-2xl font-semibold">{{ __('crm.dashboard.greeting', ['name' => auth()->user()->name]) }}</h2>

        @can('appointments.view')
            <section class="mt-8" aria-labelledby="today-heading">
                <h3 id="today-heading" class="text-base font-semibold">{{ __('crm.dashboard.today') }}</h3>
                @if ($appointments->isEmpty())
                    <p class="mt-3 text-sm text-muted">{{ __('crm.dashboard.no_appointments') }}</p>
                @else
                    <div class="panel mt-3 overflow-x-auto">
                        <table class="table-base table-stack">
                            <thead>
                            <tr>
                                <th scope="col">{{ __('crm.fields.time') }}</th>
                                <th scope="col">{{ __('crm.fields.patient') }}</th>
                                <th scope="col">{{ __('crm.fields.service') }}</th>
                                <th scope="col">{{ __('crm.fields.specialist') }}</th>
                                <th scope="col">{{ __('crm.fields.location') }}</th>
                                <th scope="col">{{ __('crm.fields.status') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($appointments as $appointment)
                                <tr>
                                    <td class="whitespace-nowrap font-medium text-heading">{{ $appointment->starts_at->setTimezone(config('optica.clinic_timezone'))->format('H:i') }}</td>
                                    <td data-label="{{ __('crm.fields.patient') }}">{{ $appointment->patient?->fullName() ?? $appointment->contact_name }}</td>
                                    <td data-label="{{ __('crm.fields.service') }}">{{ $appointment->service->name() }}</td>
                                    <td data-label="{{ __('crm.fields.specialist') }}">{{ $appointment->specialist->display_name }}</td>
                                    <td data-label="{{ __('crm.fields.location') }}">{{ $appointment->location->name() }}</td>
                                    <td data-label="{{ __('crm.fields.status') }}"><x-appointment-status :status="$appointment->status" /></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endcan
    </div>
</x-layouts.crm>
