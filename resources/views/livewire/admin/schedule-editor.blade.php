<div class="max-w-6xl space-y-8" x-data="{ week: 1 }">
    <x-admin.affected-appointments :appointments="$affected" />

    @if ($warnings)
        <div class="rounded-xl bg-mist p-4 text-sm ring-1 ring-mist-300" role="note">
            <p class="font-semibold text-ink">{{ __('catalog.schedules.cross_location_title') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">@foreach ($warnings as $w)<li>{{ $w }}</li>@endforeach</ul>
        </div>
    @endif

    <form wire:submit="save" data-preserve class="space-y-10">
        <section class="grid gap-5 md:grid-cols-2" aria-labelledby="sec-who">
            <h2 id="sec-who" class="text-base font-semibold md:col-span-2">{{ __('catalog.schedules.who_where') }}</h2>
            <div>
                <label for="specialist_id" class="field-label">{{ __('crm.fields.specialist') }} <span class="text-bad" aria-hidden="true">*</span></label>
                <select id="specialist_id" wire:model.live="specialist_id" class="input" @disabled($schedule)>
                    <option value="">—</option>
                    @foreach ($specialists as $s)<option value="{{ $s->id }}">{{ $s->display_name }}</option>@endforeach
                </select>
                @error('specialist_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="location_id" class="field-label">{{ __('crm.fields.location') }} <span class="text-bad" aria-hidden="true">*</span></label>
                <select id="location_id" wire:model.live="location_id" class="input" @disabled($schedule)>
                    <option value="">—</option>
                    @foreach ($linkedLocations as $l)<option value="{{ $l->id }}">{{ $l->name() }}</option>@endforeach
                </select>
                @error('location_id')<p class="field-error">{{ $message }}</p>@enderror
                @if ($specialist_id && $linkedLocations->isEmpty())<p class="field-hint">{{ __('catalog.schedules.no_linked_locations') }}</p>@endif
            </div>
            <x-admin.text model="name" :label="__('catalog.schedules.name')" required />
            <div class="grid grid-cols-2 gap-4">
                <x-admin.text model="valid_from" type="date" :label="__('catalog.schedules.valid_from')" required />
                <x-admin.text model="valid_to" type="date" :label="__('catalog.schedules.valid_to')" :hint="__('catalog.schedules.valid_to_hint')" />
            </div>
            <div>
                <label for="cycle_weeks" class="field-label">{{ __('catalog.schedules.cycle') }}</label>
                <select id="cycle_weeks" wire:model.live="cycle_weeks" class="input">
                    <option value="1">{{ __('catalog.schedules.cycle_options.1') }}</option>
                    <option value="2">{{ __('catalog.schedules.cycle_options.2') }}</option>
                    <option value="3">{{ __('catalog.schedules.cycle_options.3') }}</option>
                    <option value="4">{{ __('catalog.schedules.cycle_options.4') }}</option>
                </select>
            </div>
            @if ($cycle_weeks > 1)
                <x-admin.text model="cycle_anchor_date" type="date" :label="__('catalog.schedules.anchor')" :hint="__('catalog.schedules.anchor_hint')" />
            @endif
            <div class="md:col-span-2"><x-admin.toggle model="is_active" :label="__('crm.fields.is_active')" /></div>
        </section>

        <section aria-labelledby="sec-days">
            <h2 id="sec-days" class="text-base font-semibold">{{ __('catalog.schedules.days') }}</h2>
            <p class="mt-1 max-w-prose text-sm text-muted">{{ __('catalog.schedules.days_hint') }}</p>

            @if ($cycle_weeks > 1)
                <div class="mt-4 flex gap-2" role="tablist" aria-label="{{ __('catalog.schedules.cycle') }}">
                    @foreach (range(1, $cycle_weeks) as $w)
                        <button type="button" role="tab" class="min-h-11 rounded-lg px-4 text-sm font-semibold ring-1 ring-line"
                                :class="week === {{ $w }} ? 'bg-ink text-white ring-ink' : 'bg-white text-ink'"
                                :aria-selected="week === {{ $w }}" @click="week = {{ $w }}">{{ __('catalog.schedules.week_n', ['n' => $w]) }}</button>
                    @endforeach
                </div>
            @endif

            @foreach (range(1, $cycle_weeks) as $w)
                <div class="panel mt-4 divide-y divide-line" x-show="week === {{ $w }}" wire:key="week-{{ $w }}">
                    @foreach (range(1, 7) as $day)
                        <div class="px-4 py-4" wire:key="day-{{ $w }}-{{ $day }}">
                            <div class="flex flex-wrap items-center gap-3">
                                <h3 class="w-28 text-sm font-semibold text-heading">{{ __('crm.weekdays.'.$day) }}</h3>
                                <button type="button" class="btn-link text-sm" wire:click="addSegment({{ $day }}, {{ $w }})">{{ __('catalog.schedules.add_segment') }}</button>
                                @if ($day === 1)
                                    <button type="button" class="btn-link text-sm" wire:click="copyDay(1, {{ $w }})" wire:confirm="{{ __('catalog.schedules.copy_confirm') }}">{{ __('catalog.schedules.copy_monday') }}</button>
                                @endif
                            </div>
                            @php($daySegments = collect($segments)->filter(fn ($s) => $s['weekday'] === $day && $s['cycle_week'] === $w))
                            @if ($daySegments->isEmpty())
                                <p class="mt-1 text-sm text-muted">{{ __('catalog.schedules.day_off') }}</p>
                            @endif
                            <div class="mt-2 space-y-3">
                                @foreach ($daySegments as $i => $s)
                                    @php($p = "segments.$i")
                                    <div class="grid gap-3 rounded-lg bg-paper/70 p-3 sm:grid-cols-2 lg:grid-cols-[9rem_6.5rem_6.5rem_6rem_1fr_10rem_auto] lg:items-end" wire:key="seg-{{ $i }}">
                                        <div>
                                            <label class="field-label text-xs" for="{{ $p }}-type">{{ __('catalog.schedules.segment') }}</label>
                                            <select id="{{ $p }}-type" wire:model="{{ $p }}.segment" class="input">
                                                @foreach (\App\Models\ScheduleSegment::SEGMENTS as $type)<option value="{{ $type }}">{{ __('catalog.schedules.segments.'.$type) }}</option>@endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="field-label text-xs" for="{{ $p }}-start">{{ __('crm.fields.opens') }}</label>
                                            <input id="{{ $p }}-start" type="time" wire:model="{{ $p }}.start_time" class="input" @error($p.'.start_time') aria-invalid="true" @enderror>
                                        </div>
                                        <div>
                                            <label class="field-label text-xs" for="{{ $p }}-end">{{ __('crm.fields.closes') }}</label>
                                            <input id="{{ $p }}-end" type="time" wire:model="{{ $p }}.end_time" class="input" @error($p.'.end_time') aria-invalid="true" @enderror>
                                        </div>
                                        <div>
                                            <label class="field-label text-xs" for="{{ $p }}-slot">{{ __('catalog.schedules.slot') }}</label>
                                            <input id="{{ $p }}-slot" type="number" min="5" step="5" wire:model="{{ $p }}.slot_minutes" class="input">
                                        </div>
                                        <details class="relative">
                                            <summary class="input flex cursor-pointer items-center">{{ count($s['service_ids']) ? trans_choice('catalog.schedules.services_n', count($s['service_ids']), ['count' => count($s['service_ids'])]) : __('catalog.schedules.all_services') }}</summary>
                                            <div class="absolute z-10 mt-1 max-h-64 w-72 overflow-y-auto rounded-lg bg-white p-3 shadow-lg ring-1 ring-line">
                                                @forelse ($services as $svc)
                                                    <label class="flex min-h-9 items-center gap-2 text-sm">
                                                        <input type="checkbox" value="{{ $svc->id }}" wire:model="{{ $p }}.service_ids" class="h-4 w-4 rounded border-line text-ink">
                                                        {{ $svc->name() }}
                                                    </label>
                                                @empty
                                                    <p class="text-xs text-muted">{{ __('catalog.schedules.no_offerings') }}</p>
                                                @endforelse
                                            </div>
                                        </details>
                                        <div>
                                            <label class="field-label text-xs" for="{{ $p }}-res">{{ __('catalog.schedules.room') }}</label>
                                            <select id="{{ $p }}-res" wire:model="{{ $p }}.resource_id" class="input">
                                                <option value="">—</option>
                                                @foreach ($resources as $r)<option value="{{ $r->id }}">{{ $r->name[app()->getLocale()] ?? $r->name['ro'] }}</option>@endforeach
                                            </select>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <label class="flex min-h-11 items-center gap-2 text-sm">
                                                <input type="checkbox" wire:model="{{ $p }}.online_enabled" class="h-5 w-5 rounded border-line text-ink focus:ring-ink">
                                                {{ __('catalog.offerings.online_short') }}
                                            </label>
                                            <button type="button" class="btn-link text-sm text-bad" wire:click="removeSegment({{ $i }})" aria-label="{{ __('catalog.schedules.remove_segment') }}">✕</button>
                                        </div>
                                        @error($p.'.end_time')<p class="field-error sm:col-span-2 lg:col-span-7">{{ $message }}</p>@enderror
                                        @error($p.'.start_time')<p class="field-error sm:col-span-2 lg:col-span-7">{{ $message }}</p>@enderror
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </section>

        <div class="flex flex-wrap items-center gap-3 border-t border-line pt-6">
            <button type="submit" class="btn-primary">{{ __('catalog.schedules.save') }}</button>
            <a href="{{ route('admin.schedules.index', ['specialist' => $specialist_id]) }}" wire:navigate class="btn-link ml-auto text-sm">{{ __('crm.actions.back_to_list') }}</a>
        </div>
    </form>

    @if ($schedule)
        <x-admin.delete-zone :label="__('catalog.schedules.delete')" :warning="__('catalog.schedules.delete_warning')" />
    @endif
</div>
