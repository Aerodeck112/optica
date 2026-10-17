@props(['appointments'])
@if ($appointments->isNotEmpty())
    <section class="rounded-xl bg-warn-bg p-4 ring-1 ring-warn/20" aria-labelledby="affected-heading" role="region">
        <h2 id="affected-heading" class="font-semibold text-warn">{{ trans_choice('catalog.affected.title', $appointments->count(), ['count' => $appointments->count()]) }}</h2>
        <p class="mt-1 max-w-prose text-sm text-body">{{ __('catalog.affected.hint') }}</p>
        <ul class="mt-3 divide-y divide-warn/15 text-sm">
            @foreach ($appointments->take(50) as $a)
                <li class="flex flex-wrap gap-x-4 gap-y-1 py-2">
                    <span class="font-medium text-heading">{{ $a->starts_at->setTimezone(config('optica.clinic_timezone'))->locale(app()->getLocale())->isoFormat('ddd D MMM, HH:mm') }}</span>
                    <span>{{ $a->patient?->fullName() ?? $a->contact_name ?? '—' }}</span>
                    <span class="text-muted">{{ $a->service?->name() }} · {{ $a->location?->name() }}</span>
                </li>
            @endforeach
        </ul>
    </section>
@endif
