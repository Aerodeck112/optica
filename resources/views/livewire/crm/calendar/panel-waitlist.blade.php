@include('livewire.crm.calendar.panel-header', ['title' => __('calendar.waitlist.title')])
<div class="space-y-6 text-sm">
    @if ($waitlistEntries->isEmpty())
        <p class="text-muted">{{ __('calendar.waitlist.empty') }}</p>
    @else
        <ul class="space-y-2">
            @foreach ($waitlistEntries as $w)
                <li class="rounded-lg bg-paper px-3 py-2 ring-1 ring-line" wire:key="wl-{{ $w->id }}">
                    <p class="font-medium text-heading">{{ $w->displayName() }} · {{ $w->phone() }}</p>
                    <p class="text-xs text-muted">{{ $w->preferred_from->format('d.m') }}–{{ $w->preferred_to->format('d.m.Y') }} · {{ $w->service?->name() ?? __('calendar.waitlist.any_service') }} · {{ $w->specialist?->display_name ?? __('calendar.waitlist.any_specialist') }}</p>
                    @if ($w->notes)<p class="mt-1 text-xs">{{ $w->notes }}</p>@endif
                    <div class="mt-2 flex gap-4">
                        <button type="button" class="btn-link" wire:click="bookFromWaitlist({{ $w->id }})">{{ __('calendar.show.book_for') }}</button>
                        <button type="button" class="btn-link text-bad" wire:click="removeFromWaitlist({{ $w->id }})">{{ __('calendar.waitlist.remove') }}</button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <form wire:submit="addToWaitlist" class="space-y-3 border-t border-line pt-4">
        <h3 class="font-semibold">{{ __('calendar.waitlist.add') }}</h3>
        <div class="grid grid-cols-2 gap-3">
            <x-admin.text model="waitlist.contact_name" :label="__('calendar.create.contact_name')" />
            <x-admin.text model="waitlist.contact_phone" type="tel" :label="__('calendar.create.contact_phone')" />
            <x-admin.text model="waitlist.preferred_from" type="date" :label="__('calendar.waitlist.from')" />
            <x-admin.text model="waitlist.preferred_to" type="date" :label="__('calendar.waitlist.to')" />
        </div>
        <div>
            <label for="wl-service" class="field-label">{{ __('crm.fields.service') }}</label>
            <select id="wl-service" wire:model="waitlist.service_id" class="input">
                <option value="">{{ __('calendar.waitlist.any_service') }}</option>
                @foreach (\App\Models\Service::where('is_active', true)->orderBy('sort_order')->get() as $svc)<option value="{{ $svc->id }}">{{ $svc->name() }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="wl-sp" class="field-label">{{ __('crm.fields.specialist') }}</label>
            <select id="wl-sp" wire:model="waitlist.specialist_id" class="input">
                <option value="">{{ __('calendar.waitlist.any_specialist') }}</option>
                @foreach ($specialists as $sp)<option value="{{ $sp->id }}">{{ $sp->display_name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="wl-locale" class="field-label">{{ __('calendar.create.locale') }}</label>
            <select id="wl-locale" wire:model="waitlist.locale" class="input">
                @foreach (config('optica.locales') as $l)<option value="{{ $l }}">{{ __('crm.languages.'.$l) }}</option>@endforeach
            </select>
        </div>
        <x-admin.text model="waitlist.notes" :label="__('calendar.waitlist.notes')" />
        <button type="submit" class="btn-primary w-full">{{ __('calendar.waitlist.add') }}</button>
    </form>
</div>
