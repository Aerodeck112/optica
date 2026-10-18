@php
    $tz = config('optica.clinic_timezone');
    $loc = app()->getLocale();
    $steps = [1 => __('site.booking.steps.location'), 2 => __('site.booking.steps.service'), 3 => __('site.booking.steps.specialist'), 4 => __('site.booking.steps.time'), 5 => __('site.booking.steps.details')];
@endphp
<div class="bg-paper">
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 sm:py-14">
        <h1 class="text-3xl font-light text-heading sm:text-4xl">{{ __('site.booking.title') }}</h1>
        <p class="mt-3 max-w-prose text-body">{{ __('site.booking.intro') }}</p>

        @if ($step <= 5)
            {{-- This is a real sequence, so the steps are numbered. --}}
            <ol class="mt-8 flex flex-wrap gap-x-6 gap-y-2 text-sm" aria-label="{{ __('site.booking.progress') }}">
                @foreach ($steps as $n => $label)
                    <li @class(['flex items-center gap-2', 'font-semibold text-ink' => $n === $step, 'text-muted' => $n !== $step]) @if ($n === $step) aria-current="step" @endif>
                        <span @class(['flex h-7 w-7 items-center justify-center rounded-full text-xs', 'bg-ink text-white' => $n === $step, 'bg-lens text-ink' => $n < $step, 'bg-white ring-1 ring-line' => $n > $step])>{{ $n }}</span>
                        {{ $label }}
                    </li>
                @endforeach
            </ol>
        @endif

        {{-- Summary of choices so far --}}
        @if ($step > 1 && $step <= 5)
            <dl class="mt-6 flex flex-wrap gap-x-8 gap-y-2 text-sm">
                <div><dt class="text-muted">{{ __('site.booking.steps.location') }}</dt><dd class="font-medium text-heading">{{ $selectedLocation ? ($selectedLocation->publicValue('name') ?? $selectedLocation->name()) : __('site.booking.any_location') }}</dd></div>
                @if ($service && $step > 2)<div><dt class="text-muted">{{ __('site.booking.steps.service') }}</dt><dd class="font-medium text-heading">{{ $service->publicValue('name') ?? $service->name() }}</dd></div>@endif
            </dl>
        @endif

        <div class="mt-8" aria-live="polite">
            @error('slot')<p class="mb-4 rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad" role="alert">{{ $message }}</p>@enderror
            @error('form')<p class="mb-4 rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad" role="alert">{{ $message }}</p>@enderror

            @switch($step)
                @case(1)
                    <h2 class="text-xl font-semibold">{{ __('site.booking.choose_location') }}</h2>
                    @if ($locations->isEmpty())
                        <p class="mt-4">{{ __('site.booking.unavailable') }}</p>
                    @else
                        <ul class="mt-4 grid gap-3 sm:grid-cols-2">
                            <li><button type="button" wire:click="chooseLocation(null)" class="w-full rounded-xl bg-white p-4 text-left ring-1 ring-line hover:ring-ink">
                                <span class="block font-semibold text-heading">{{ __('site.booking.any_location') }}</span>
                                <span class="block text-sm text-muted">{{ __('site.booking.any_location_hint') }}</span>
                            </button></li>
                            @foreach ($locations as $l)
                                <li><button type="button" wire:click="chooseLocation('{{ $l->code }}')" class="w-full rounded-xl bg-white p-4 text-left ring-1 ring-line hover:ring-ink">
                                    <span class="block font-semibold text-heading">{{ $l->publicValue('city') ?? $l->city }}</span>
                                    <span class="block text-sm text-body">{{ $l->publicValue('address') ?? $l->address }}</span>
                                </button></li>
                            @endforeach
                        </ul>
                    @endif
                    @break

                @case(2)
                    <h2 class="text-xl font-semibold">{{ __('site.booking.choose_service') }}</h2>
                    @if ($services->isEmpty())
                        <p class="mt-4">{{ __('site.booking.no_services') }}</p>
                    @else
                        <ul class="mt-4 space-y-3">
                            @foreach ($services as $s)
                                <li><button type="button" wire:click="chooseService({{ $s->id }})" class="w-full rounded-xl bg-white p-4 text-left ring-1 ring-line hover:ring-ink">
                                    <span class="block font-semibold text-heading">{{ $s->publicValue('name') ?? $s->name() }}</span>
                                    @if ($s->publicValue('description'))<span class="mt-1 block text-sm text-body">{{ \Illuminate\Support\Str::limit($s->publicValue('description'), 180) }}</span>@endif
                                    @if ($s->publicValue('preparation'))<span class="mt-2 block text-sm text-ink">{{ $s->publicValue('preparation') }}</span>@endif
                                </button></li>
                            @endforeach
                        </ul>
                    @endif
                    <button type="button" wire:click="back" class="btn-link mt-6 text-sm">{{ __('site.booking.back') }}</button>
                    @break

                @case(3)
                    <h2 class="text-xl font-semibold">{{ __('site.booking.choose_specialist') }}</h2>
                    <ul class="mt-4 space-y-3">
                        <li><button type="button" wire:click="chooseSpecialist('first')" class="w-full rounded-xl bg-ink p-4 text-left text-white hover:bg-ink-700">
                            <span class="block font-semibold">{{ __('site.booking.first_available') }}</span>
                            <span class="block text-sm text-mist-300">{{ __('site.booking.first_available_hint') }}</span>
                        </button></li>
                        @foreach ($specialists as $sp)
                            <li><button type="button" wire:click="chooseSpecialist('{{ $sp->id }}')" class="flex w-full items-center gap-4 rounded-xl bg-white p-4 text-left ring-1 ring-line hover:ring-ink">
                                @if ($sp->photo_path)<img src="{{ Storage::disk('public')->url($sp->photo_path) }}" alt="" class="h-14 w-14 rounded-full object-cover">@endif
                                <span>
                                    <span class="block font-semibold text-heading">{{ $sp->display_name }}</span>
                                    <span class="block text-sm text-muted">{{ $sp->publicValue('title') ?? __('crm.specialists.types.'.$sp->type) }}</span>
                                    @if ($sp->languages)<span class="block text-xs text-muted">{{ __('site.booking.speaks') }}: {{ collect($sp->languages)->map(fn ($l) => __('crm.spoken.'.$l))->join(', ') }}</span>@endif
                                </span>
                            </button></li>
                        @endforeach
                        @foreach ($paused as $p)
                            <li class="rounded-xl bg-white/60 p-4 ring-1 ring-line">
                                <span class="block font-semibold text-heading">{{ $p->specialist->display_name }}</span>
                                <span class="block text-sm text-muted">{{ $p->content[$loc]['pause_message'] ?? null ?: __('site.booking.paused_default') }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <button type="button" wire:click="back" class="btn-link mt-6 text-sm">{{ __('site.booking.back') }}</button>
                    @break

                @case(4)
                    <h2 class="text-xl font-semibold">{{ __('site.booking.choose_time') }}</h2>
                    <div class="mt-4 flex items-center gap-2">
                        <button type="button" class="btn-secondary px-3" wire:click="shiftDays(-1)" @disabled($weekOffset === 0) aria-label="{{ __('site.booking.earlier') }}">‹</button>
                        <div class="flex flex-1 gap-2 overflow-x-auto pb-1" role="listbox" aria-label="{{ __('site.booking.days') }}">
                            @foreach ($days as $d)
                                @php($key = $d->toDateString())
                                @php($has = ! empty($timeSlots[$key]))
                                <button type="button" role="option" aria-selected="{{ $date === $key ? 'true' : 'false' }}" wire:click="chooseDate('{{ $key }}')" @disabled(! $has)
                                        @class(['flex min-w-16 flex-col items-center rounded-lg px-2 py-2 text-sm', 'bg-ink text-white' => $date === $key, 'bg-white ring-1 ring-line hover:ring-ink' => $date !== $key && $has, 'bg-transparent text-muted/60' => ! $has])>
                                    <span class="text-xs">{{ $d->locale($loc)->isoFormat('ddd') }}</span>
                                    <span class="text-lg font-semibold">{{ $d->day }}</span>
                                    <span class="text-xs">{{ $d->locale($loc)->isoFormat('MMM') }}</span>
                                </button>
                            @endforeach
                        </div>
                        <button type="button" class="btn-secondary px-3" wire:click="shiftDays(1)" aria-label="{{ __('site.booking.later') }}">›</button>
                    </div>

                    @if (empty($timeSlots))
                        <p class="mt-6 rounded-lg bg-white p-4 ring-1 ring-line">{{ __('site.booking.no_slots') }}</p>
                    @elseif ($date && ! empty($timeSlots[$date]))
                        <h3 class="mt-6 font-semibold">{{ ucfirst(\Carbon\CarbonImmutable::parse($date, $tz)->locale($loc)->isoFormat('dddd, D MMMM')) }}</h3>
                        <ul class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-5">
                            @foreach ($timeSlots[$date] as $slot)
                                <li><button type="button" wire:click="chooseSlot({{ $slot['offering_id'] }}, '{{ $slot['starts_at'] }}')" wire:loading.attr="disabled"
                                            class="w-full rounded-lg bg-white px-2 py-3 text-center ring-1 ring-line hover:bg-lens hover:ring-lens">
                                    <span class="block text-base font-semibold text-heading">{{ $slot['local'] }}</span>
                                    @if ($specialistChoice === 'first')<span class="block truncate text-xs text-muted">{{ $slot['specialist'] }}</span>@endif
                                    @if ($anyLocation)<span class="block truncate text-xs text-muted">{{ $slot['location'] }}</span>@endif
                                </button></li>
                            @endforeach
                        </ul>
                    @endif
                    <button type="button" wire:click="back" class="btn-link mt-6 text-sm">{{ __('site.booking.back') }}</button>
                    @break

                @case(5)
                    @if ($hold)
                        <div class="rounded-xl bg-white p-5 ring-1 ring-line">
                            <p class="text-sm text-muted">{{ __('site.booking.your_slot') }}</p>
                            <p class="mt-1 text-lg font-semibold text-heading">{{ ucfirst($hold->starts_at->setTimezone($tz)->locale($loc)->isoFormat('dddd, D MMMM YYYY, HH:mm')) }}</p>
                            <p class="text-sm">{{ $hold->service->publicValue('name') ?? $hold->service->name() }} · {{ $hold->specialist->display_name }}</p>
                            <p class="text-sm">{{ $hold->location->publicValue('name') ?? $hold->location->name() }}</p>
                            <p class="mt-3 text-xs text-muted">{{ __('site.booking.hold_note', ['minutes' => $holdMinutes]) }}</p>
                        </div>
                    @endif

                    <form wire:submit="submit" class="mt-6 space-y-5" novalidate>
                        <div class="hidden" aria-hidden="true"><label>Website <input type="text" wire:model="website" tabindex="-1" autocomplete="off"></label></div>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-admin.text model="name" :label="__('site.booking.name')" required autocomplete="name" />
                            <x-admin.text model="phone" type="tel" :label="__('site.booking.phone')" required autocomplete="tel" :hint="__('site.booking.phone_hint')" />
                            <x-admin.text model="email" type="email" :label="__('site.booking.email')" autocomplete="email" :hint="__('site.booking.email_hint')" />
                            <div>
                                <label for="patientLocale" class="field-label">{{ __('site.booking.language') }}</label>
                                <select id="patientLocale" wire:model="patientLocale" class="input">
                                    <option value="ro">Română</option>
                                    <option value="hu">Magyar</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label for="message" class="field-label">{{ __('site.booking.message') }}</label>
                            <textarea id="message" wire:model="message" rows="2" maxlength="500" class="input" aria-describedby="message-hint"></textarea>
                            <p id="message-hint" class="field-hint">{{ __('site.booking.message_hint') }}</p>
                        </div>

                        <div class="space-y-3 rounded-xl bg-white p-4 ring-1 ring-line">
                            <label class="flex items-start gap-3 text-sm">
                                <input type="checkbox" wire:model="privacy" class="mt-0.5 h-5 w-5 shrink-0 rounded border-line text-ink focus:ring-ink" @error('privacy') aria-invalid="true" @enderror>
                                <span>{!! __('site.booking.privacy', ['link' => '<a class="btn-link" target="_blank" href="'.e(\App\Support\Ui\SiteUrl::path('privacy-policy')).'">'.e(__('site.footer.privacy')).'</a>']) !!}</span>
                            </label>
                            @error('privacy')<p class="field-error">{{ __('site.booking.privacy_required') }}</p>@enderror
                            <fieldset>
                                <legend class="text-sm font-medium text-heading">{{ __('site.booking.marketing') }}</legend>
                                <p class="text-xs text-muted">{{ __('site.booking.marketing_hint') }}</p>
                                <div class="mt-2 flex flex-wrap gap-5">
                                    @foreach (['email' => 'Email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'] as $ch => $label)
                                        <label class="flex min-h-11 items-center gap-2 text-sm">
                                            <input type="checkbox" wire:model="marketing.{{ $ch }}" class="h-5 w-5 rounded border-line text-ink focus:ring-ink"> {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                        </div>

                        <div class="flex flex-wrap items-center gap-4">
                            <button type="submit" class="btn bg-lens px-6 text-ink hover:bg-lens-600" wire:loading.attr="disabled">{{ __('site.booking.submit') }}</button>
                            <button type="button" wire:click="back" class="btn-link text-sm">{{ __('site.booking.change_time') }}</button>
                        </div>
                    </form>
                    @break

                @default
                    <div class="rounded-2xl bg-white p-6 ring-1 ring-line sm:p-8" role="status">
                        @if ($finalStatus === 'confirmed')
                            <h2 class="text-2xl font-light text-ok">{{ __('site.booking.done_confirmed') }}</h2>
                        @else
                            <h2 class="text-2xl font-light text-ink">{{ __('site.booking.done_pending') }}</h2>
                            <p class="mt-2">{{ __('site.booking.done_pending_text') }}</p>
                        @endif
                        @if ($hold)
                            <p class="mt-4 text-lg font-semibold text-heading">{{ ucfirst($hold->starts_at->setTimezone($tz)->locale($loc)->isoFormat('dddd, D MMMM YYYY, HH:mm')) }}</p>
                            <p>{{ $hold->service->publicValue('name') ?? $hold->service->name() }} · {{ $hold->specialist->display_name }}</p>
                            <p>{{ $hold->location->publicValue('name') ?? $hold->location->name() }}</p>
                        @endif
                        @if ($manageUrl)
                            <p class="mt-6 text-sm">{{ __('site.booking.manage_hint') }}</p>
                            <a href="{{ $manageUrl }}" class="btn-secondary mt-3">{{ __('site.booking.manage_link') }}</a>
                        @endif
                    </div>
            @endswitch
        </div>
    </div>
</div>
