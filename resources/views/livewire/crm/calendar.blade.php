@php
    $px = \App\Livewire\Crm\Calendar::PX_PER_MINUTE;
    $gridMinutes = ($endHour - $startHour) * 60;
    $todayLocal = now($tz)->toDateString();
@endphp
<div wire:poll.{{ $pollSeconds }}s
     x-data="{ dragging: null, toast: null }"
     x-init="@if (! request()->has('view')) if (window.innerWidth < 768 && $wire.view !== 'agenda') { $wire.set('view', 'agenda') } @endif"
     @notify.window="toast = $event.detail.message; setTimeout(() => toast = null, 3500)">

    {{-- Toolbar --}}
    <div class="flex flex-wrap items-end gap-3">
        @if ($locations->count() > 1)
            <div>
                <label for="cal-location" class="field-label">{{ __('crm.fields.location') }}</label>
                <select id="cal-location" wire:model.live="locationId" class="input">
                    @foreach ($locations as $l)<option value="{{ $l->id }}">{{ $l->name() }}</option>@endforeach
                </select>
            </div>
        @endif
        <div>
            <label for="cal-specialist" class="field-label">{{ __('crm.fields.specialist') }}</label>
            <select id="cal-specialist" wire:model.live="specialistId" class="input">
                <option value="">{{ __('calendar.all_specialists') }}</option>
                @foreach ($specialists as $s)<option value="{{ $s->id }}">{{ $s->display_name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="cal-status" class="field-label">{{ __('crm.fields.status') }}</label>
            <select id="cal-status" wire:model.live="statusFilter" class="input">
                @foreach (['active', 'cancelled', 'all'] as $f)<option value="{{ $f }}">{{ __('calendar.status_filter.'.$f) }}</option>@endforeach
            </select>
        </div>
        @if ($canCreate)
            <button type="button" class="btn-secondary ml-auto" wire:click="openWaitlist">{{ __('calendar.waitlist.button', ['count' => $waitlistEntries->count()]) }}</button>
        @endif
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-3">
        <div class="flex rounded-lg bg-white p-0.5 ring-1 ring-line" role="group" aria-label="{{ __('calendar.title') }}">
            @foreach (['day', 'week', 'month', 'agenda'] as $v)
                <button type="button" wire:click="$set('view', '{{ $v }}')" aria-pressed="{{ $view === $v ? 'true' : 'false' }}"
                        @class(['min-h-10 rounded-md px-3 text-sm font-semibold', 'bg-ink text-white' => $view === $v, 'text-ink hover:bg-mist' => $view !== $v])>{{ __('calendar.views.'.$v) }}</button>
            @endforeach
        </div>
        <div class="flex items-center gap-1">
            <button type="button" class="btn-secondary px-3" wire:click="go('prev')" aria-label="{{ __('calendar.previous') }}">‹</button>
            <button type="button" class="btn-secondary" wire:click="go('today')">{{ __('calendar.today') }}</button>
            <button type="button" class="btn-secondary px-3" wire:click="go('next')" aria-label="{{ __('calendar.next') }}">›</button>
        </div>
        <label class="sr-only" for="cal-date">{{ __('calendar.create.date') }}</label>
        <input id="cal-date" type="date" wire:model.live="date" class="input w-auto">
        <p class="text-lg font-semibold text-heading">
            @switch($view)
                @case('week') {{ $from->locale(app()->getLocale())->isoFormat('D MMM') }} – {{ $to->locale(app()->getLocale())->isoFormat('D MMM YYYY') }} @break
                @case('month') {{ ucfirst($day->locale(app()->getLocale())->isoFormat('MMMM YYYY')) }} @break
                @default {{ ucfirst($day->locale(app()->getLocale())->isoFormat('dddd, D MMMM YYYY')) }}
            @endswitch
        </p>
        <p class="ml-auto text-xs text-muted" aria-live="polite">
            <span wire:offline.remove>{{ __('calendar.updated_at', ['time' => $lastRefresh]) }}</span>
            <span wire:offline class="font-semibold text-bad">{{ __('calendar.offline') }}</span>
        </p>
    </div>

    <div class="mt-5 lg:flex lg:items-start lg:gap-6">
        <div class="min-w-0 flex-1">
            @if (! $locationId)
                <p class="text-sm text-muted">{{ __('calendar.no_location') }}</p>
            @elseif ($view === 'month')
                @include('livewire.crm.calendar.month')
            @elseif ($view === 'agenda')
                @include('livewire.crm.calendar.agenda')
            @else
                @include('livewire.crm.calendar.grid')
            @endif
        </div>

        @if ($panel)
            <aside class="fixed inset-0 z-40 overflow-y-auto bg-paper p-4 sm:p-6 lg:sticky lg:top-20 lg:z-0 lg:max-h-[calc(100dvh-6rem)] lg:w-[24rem] lg:shrink-0 lg:rounded-xl lg:bg-white lg:p-5 lg:ring-1 lg:ring-line"
                   aria-labelledby="panel-title" @keydown.escape.window="$wire.closePanel()">
                @include('livewire.crm.calendar.panel-'.$panel)
            </aside>
        @endif
    </div>

    <div x-show="toast" x-cloak x-transition.opacity class="fixed bottom-4 left-1/2 z-50 -translate-x-1/2 rounded-lg bg-ink px-4 py-3 text-sm font-medium text-white shadow-lg" role="status" x-text="toast"></div>
</div>
