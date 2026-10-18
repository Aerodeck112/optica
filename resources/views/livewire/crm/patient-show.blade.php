<div class="max-w-6xl">
    {{-- Identity bar: always visible so the right person is never in doubt. --}}
    <div class="flex flex-wrap items-start gap-x-6 gap-y-2">
        <div class="min-w-0">
            <p class="text-sm text-muted">{{ $patient->code }}
                @if ($patient->birth_date) · {{ $patient->birth_date->format('d.m.Y') }} ({{ trans_choice('patients.age', $patient->age(), ['count' => $patient->age()]) }})@endif
                · <span lang="{{ $patient->locale }}">{{ __('crm.languages.'.$patient->locale) }}</span></p>
            <p class="mt-1 text-sm">
                @if ($patient->phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $patient->phone) }}" class="btn-link">{{ $patient->phone }}</a>@endif
                @if ($patient->email)<span class="text-muted"> · </span><a href="mailto:{{ $patient->email }}" class="btn-link">{{ $patient->email }}</a>@endif
            </p>
        </div>
        <div class="ml-auto flex flex-wrap items-center gap-2">
            @if ($patient->archived_at)<span class="badge-neutral">{{ __('patients.archived') }}</span>@endif
            @if ($patient->legalGuardian)<span class="badge-info">{{ __('patients.minor_with_guardian') }}</span>@endif
            @can('appointments.create')
                <a href="{{ route('crm.calendar') }}" wire:navigate class="btn-secondary">{{ __('patients.book') }}</a>
            @endcan
        </div>
    </div>

    <div aria-live="polite">
        @if ($notice)<div class="mt-4 rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ $notice }}</div>@endif
    </div>

    <nav class="-mx-4 mt-6 overflow-x-auto border-b border-line px-4 sm:mx-0 sm:px-0" aria-label="{{ __('patients.sections') }}">
        <ul class="flex min-w-max gap-1" role="list">
            @foreach ($tabs as $t)
                <li>
                    <button type="button" wire:click="$set('tab', '{{ $t }}')"
                            @class(['relative min-h-11 px-3 text-sm font-medium', 'text-ink after:absolute after:inset-x-2 after:bottom-0 after:h-0.5 after:rounded-full after:bg-ink' => $tab === $t, 'text-muted hover:text-ink' => $tab !== $t])
                            @if ($tab === $t) aria-current="page" @endif>{{ __('patients.tabs.'.$t) }}</button>
                </li>
            @endforeach
        </ul>
    </nav>

    <div class="mt-6">
        @if ($tab === 'overview')
            @include('livewire.crm.patient.overview')
        @elseif ($tab === 'appointments')
            @include('livewire.crm.patient.appointments')
        @elseif ($tab === 'records')
            <livewire:crm.patient.records-tab :patient-id="$patient->id" :key="'records-'.$patient->id" />
        @elseif ($tab === 'prescriptions')
            <livewire:crm.patient.prescriptions-tab :patient-id="$patient->id" :key="'rx-'.$patient->id" />
        @elseif ($tab === 'orders')
            <livewire:crm.patient.orders-tab :patient-id="$patient->id" :key="'orders-'.$patient->id" />
        @elseif ($tab === 'finance')
            <livewire:crm.patient.finance-tab :patient-id="$patient->id" :key="'finance-'.$patient->id" />
        @elseif ($tab === 'documents')
            <livewire:crm.patient.documents-tab :patient-id="$patient->id" :key="'docs-'.$patient->id" />
        @elseif ($tab === 'consents')
            @include('livewire.crm.patient.consents')
        @elseif ($tab === 'tasks')
            <livewire:crm.task-list subject-type="patient" :subject-id="$patient->id" :key="'tasks-'.$patient->id" />
        @elseif ($tab === 'history')
            <ol class="panel divide-y divide-line" role="list">
                @forelse ($timeline as $item)
                    <li class="flex gap-4 px-4 py-3">
                        <time class="w-28 shrink-0 text-xs text-muted" datetime="{{ $item['at']?->toIso8601String() }}">{{ $item['at']?->timezone($tz)->format('d.m.Y H:i') }}</time>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('patients.timeline.types.'.$item['type']) }}</p>
                            <p class="text-sm text-heading">{{ $item['title'] }}</p>
                            <p class="text-xs text-muted">{{ $item['meta'] }}</p>
                        </div>
                    </li>
                @empty
                    <li class="px-4 py-10 text-center text-sm text-muted">{{ __('crm.empty_list') }}</li>
                @endforelse
            </ol>
        @endif
    </div>
</div>
