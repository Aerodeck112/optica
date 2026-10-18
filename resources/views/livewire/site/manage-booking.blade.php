@php($tz = config('optica.clinic_timezone'))
@php($loc = app()->getLocale())
<div class="bg-paper">
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6 sm:py-14">
        <h1 class="text-3xl font-light text-heading">{{ __('site.manage.title') }}</h1>
        @if ($notice)<p class="mt-6 rounded-lg bg-ok-bg px-4 py-3 text-ok" role="status">{{ $notice }}</p>@endif

        <div class="mt-6 rounded-2xl bg-white p-6 ring-1 ring-line">
            <p class="text-sm text-muted">{{ __('appointments.status.'.$a->status->value) }}</p>
            <p class="mt-1 text-xl font-semibold text-heading">{{ ucfirst($a->starts_at->setTimezone($tz)->locale($loc)->isoFormat('dddd, D MMMM YYYY, HH:mm')) }}</p>
            <p>{{ $a->service->publicValue('name') ?? $a->service->name() }} · {{ $a->specialist->display_name }}</p>
            <p>{{ $a->location->publicValue('name') ?? $a->location->name() }}</p>
            @if ($a->location->phone)<p class="mt-2 text-sm">{{ __('site.manage.questions') }} <a href="tel:{{ preg_replace('/[^0-9+]/', '', $a->location->phone) }}" class="btn-link">{{ $a->location->phone }}</a></p>@endif
        </div>

        @if ($editable)
            <section class="mt-10" aria-labelledby="move-title">
                <h2 id="move-title" class="text-xl font-semibold">{{ __('site.manage.move') }}</h2>
                @error('slot')<p class="mt-3 rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad" role="alert">{{ $message }}</p>@enderror
                @if (empty($timeSlots))
                    <p class="mt-3">{{ __('site.booking.no_slots') }}</p>
                @else
                    <div class="mt-4 flex gap-2 overflow-x-auto pb-1">
                        @foreach (array_keys($timeSlots) as $d)
                            @php($day = \Carbon\CarbonImmutable::parse($d, $tz))
                            <button type="button" wire:click="$set('date', '{{ $d }}')" aria-pressed="{{ $date === $d ? 'true' : 'false' }}"
                                    @class(['flex min-w-16 flex-col items-center rounded-lg px-2 py-2 text-sm', 'bg-ink text-white' => $date === $d, 'bg-white ring-1 ring-line' => $date !== $d])>
                                <span class="text-xs">{{ $day->locale($loc)->isoFormat('ddd') }}</span>
                                <span class="text-lg font-semibold">{{ $day->day }}</span>
                            </button>
                        @endforeach
                    </div>
                    <ul class="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-6">
                        @foreach ($timeSlots[$date] ?? [] as $s)
                            <li><button type="button" wire:click="reschedule('{{ $s['utc'] }}')" wire:confirm="{{ __('site.manage.move_confirm', ['time' => $s['local']]) }}"
                                        class="w-full rounded-lg bg-white py-3 font-semibold ring-1 ring-line hover:bg-lens">{{ $s['local'] }}</button></li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="mt-10 border-t border-line pt-8" aria-labelledby="cancel-title">
                <h2 id="cancel-title" class="text-xl font-semibold">{{ __('site.manage.cancel') }}</h2>
                @if ($confirmingCancel)
                    <p class="mt-3">{{ __('site.manage.cancel_confirm') }}</p>
                    <div class="mt-4 flex gap-3">
                        <button type="button" class="btn-danger" wire:click="cancel">{{ __('site.manage.cancel_yes') }}</button>
                        <button type="button" class="btn-secondary" wire:click="$set('confirmingCancel', false)">{{ __('site.manage.cancel_no') }}</button>
                    </div>
                @else
                    <button type="button" class="btn-danger mt-3" wire:click="$set('confirmingCancel', true)">{{ __('site.manage.cancel') }}</button>
                @endif
            </section>
        @else
            <p class="mt-6 text-sm text-muted">{{ __('site.manage.not_editable') }}</p>
        @endif
    </div>
</div>
