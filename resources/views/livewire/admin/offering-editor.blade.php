<div class="max-w-6xl">
    <p class="max-w-prose text-sm text-muted">{{ __('catalog.offerings.intro') }}</p>

    @if ($locations->isEmpty())
        <div class="mt-8 max-w-prose">
            <p class="font-medium text-heading">{{ __('catalog.offerings.no_locations') }}</p>
            <a href="{{ route('admin.specialists.edit', $specialist) }}" wire:navigate class="btn-secondary mt-4">{{ __('catalog.offerings.go_to_profile') }}</a>
        </div>
    @else
        <form wire:submit="save" data-preserve class="mt-6 space-y-12">
            @foreach ($locations as $location)
                <section aria-labelledby="loc-{{ $location->id }}" wire:key="off-loc-{{ $location->id }}">
                    <h2 id="loc-{{ $location->id }}" class="text-lg font-semibold">{{ $location->name() }}</h2>
                    @unless ($location->online_booking_enabled)
                        <p class="mt-1 text-sm text-warn">{{ __('catalog.offerings.location_online_off') }}</p>
                    @endunless

                    <div class="panel mt-4 overflow-x-auto">
                        <table class="table-base table-stack">
                            <thead>
                            <tr>
                                <th scope="col">{{ __('crm.fields.service') }}</th>
                                <th scope="col">{{ __('catalog.offerings.duration') }}</th>
                                <th scope="col">{{ __('catalog.offerings.capacity') }}</th>
                                <th scope="col">{{ __('catalog.offerings.buffers') }}</th>
                                <th scope="col">{{ __('catalog.offerings.price') }}</th>
                                <th scope="col">{{ __('catalog.offerings.equipment') }}</th>
                                <th scope="col">{{ __('crm.fields.online_booking') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($rows[$location->id] as $serviceId => $row)
                                @php($service = $services[$serviceId] ?? null)
                                @continue(! $service)
                                @php($base = "rows.{$location->id}.{$serviceId}")
                                <tr wire:key="off-{{ $location->id }}-{{ $serviceId }}" @class(['opacity-60' => ! $row['offered']])>
                                    <td>
                                        <label class="flex min-h-11 items-center gap-3">
                                            <input type="checkbox" wire:model.live="{{ $base }}.offered" class="h-5 w-5 rounded border-line text-ink focus:ring-ink">
                                            <span class="font-medium text-heading">{{ $service->name() }}</span>
                                        </label>
                                    </td>
                                    <td data-label="{{ __('catalog.offerings.duration') }}">
                                        <label class="sr-only" for="{{ $base }}-d">{{ __('catalog.offerings.duration') }}</label>
                                        <input id="{{ $base }}-d" type="number" min="5" step="5" wire:model="{{ $base }}.duration" placeholder="{{ $service->default_duration }}" class="input w-24" @disabled(! $row['offered'])>
                                        @error($base.'.duration')<p class="field-error">{{ $message }}</p>@enderror
                                    </td>
                                    <td data-label="{{ __('catalog.offerings.capacity') }}">
                                        <label class="sr-only" for="{{ $base }}-c">{{ __('catalog.offerings.capacity') }}</label>
                                        <input id="{{ $base }}-c" type="number" min="1" max="20" wire:model="{{ $base }}.capacity" class="input w-20" @disabled(! $row['offered'])>
                                    </td>
                                    <td data-label="{{ __('catalog.offerings.buffers') }}">
                                        <div class="flex items-center gap-1">
                                            <label class="sr-only" for="{{ $base }}-bb">{{ __('crm.fields.buffer_before') }}</label>
                                            <input id="{{ $base }}-bb" type="number" min="0" step="5" wire:model="{{ $base }}.buffer_before" placeholder="{{ $service->buffer_before }}" class="input w-20" @disabled(! $row['offered'])>
                                            <span aria-hidden="true">/</span>
                                            <label class="sr-only" for="{{ $base }}-ba">{{ __('crm.fields.buffer_after') }}</label>
                                            <input id="{{ $base }}-ba" type="number" min="0" step="5" wire:model="{{ $base }}.buffer_after" placeholder="{{ $service->buffer_after }}" class="input w-20" @disabled(! $row['offered'])>
                                        </div>
                                    </td>
                                    <td data-label="{{ __('catalog.offerings.price') }}">
                                        <label class="sr-only" for="{{ $base }}-p">{{ __('catalog.offerings.price') }}</label>
                                        <input id="{{ $base }}-p" type="text" inputmode="decimal" wire:model="{{ $base }}.price" placeholder="{{ $service->default_price ?? '—' }}" class="input w-28" @disabled(! $row['offered'])>
                                        @error($base.'.price')<p class="field-error">{{ $message }}</p>@enderror
                                    </td>
                                    <td data-label="{{ __('catalog.offerings.equipment') }}">
                                        <label class="sr-only" for="{{ $base }}-r">{{ __('catalog.offerings.equipment') }}</label>
                                        <select id="{{ $base }}-r" wire:model="{{ $base }}.resource_type" class="input" @disabled(! $row['offered'])>
                                            <option value="">{{ __('catalog.offerings.no_equipment') }}</option>
                                            @foreach ($resourceTypes[$location->id] as $t)
                                                <option value="{{ $t }}">{{ __('catalog.resources.types.'.$t) }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td data-label="{{ __('crm.fields.online_booking') }}">
                                        <label class="flex min-h-11 items-center gap-2 text-sm">
                                            <input type="checkbox" wire:model="{{ $base }}.online" class="h-5 w-5 rounded border-line text-ink focus:ring-ink" @disabled(! $row['offered'])>
                                            <span class="sr-only md:not-sr-only">{{ __('catalog.offerings.online_short') }}</span>
                                        </label>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    @php($o = "online.{$location->id}")
                    <fieldset class="mt-6 grid gap-5 md:grid-cols-3">
                        <legend class="mb-3 text-sm font-semibold text-heading">{{ __('catalog.offerings.online_settings') }}</legend>
                        <x-admin.text model="{{ $o }}.min_lead_hours" type="number" min="0" :label="__('catalog.offerings.min_lead_hours')" :hint="__('catalog.offerings.min_lead_hint')" />
                        <x-admin.text model="{{ $o }}.max_advance_days" type="number" min="1" :label="__('catalog.offerings.max_advance_days')" />
                        <div class="space-y-3 pt-7">
                            <x-admin.toggle model="{{ $o }}.paused" :label="__('catalog.offerings.paused')" />
                            <x-admin.toggle model="{{ $o }}.notify" :label="__('catalog.offerings.notify')" />
                        </div>
                        <x-admin.text model="{{ $o }}.pause_ro" :label="__('catalog.offerings.pause_message').' (RO)'" class="md:col-span-1" />
                        <x-admin.text model="{{ $o }}.pause_hu" :label="__('catalog.offerings.pause_message').' (HU)'" />
                    </fieldset>
                </section>
            @endforeach

            <div class="sticky bottom-0 -mx-4 flex flex-wrap items-center gap-3 border-t border-line bg-paper/95 px-4 py-4 backdrop-blur sm:-mx-6 sm:px-6 lg:static lg:mx-0 lg:bg-transparent lg:px-0">
                <button type="submit" class="btn-primary">{{ __('catalog.offerings.save') }}</button>
                <a href="{{ route('admin.specialists.edit', $specialist) }}" wire:navigate class="btn-link ml-auto text-sm">{{ __('catalog.offerings.go_to_profile') }}</a>
            </div>
        </form>
    @endif
</div>
