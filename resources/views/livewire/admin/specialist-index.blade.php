<div class="max-w-6xl">
    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full max-w-xs">
            <label for="search" class="field-label">{{ __('crm.actions.search') }}</label>
            <input id="search" type="search" wire:model.live.debounce.300ms="search" class="input" placeholder="{{ __('crm.specialists.search_placeholder') }}">
        </div>
        <div>
            <label for="type" class="field-label">{{ __('crm.fields.type') }}</label>
            <select id="type" wire:model.live="type" class="input">
                <option value="">{{ __('crm.all') }}</option>
                @foreach (\App\Models\Specialist::TYPES as $t)
                    <option value="{{ $t }}">{{ __('crm.specialists.types.'.$t) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="location" class="field-label">{{ __('crm.fields.location') }}</label>
            <select id="location" wire:model.live="location" class="input">
                <option value="">{{ __('crm.all') }}</option>
                @foreach ($locations as $l)
                    <option value="{{ $l->id }}">{{ $l->name() }}</option>
                @endforeach
            </select>
        </div>
        <label class="flex min-h-11 items-center gap-2 text-sm">
            <input type="checkbox" wire:model.live="showDeleted" class="h-5 w-5 rounded border-line text-ink focus:ring-ink">
            {{ __('crm.show_deleted') }}
        </label>
        <a href="{{ route('admin.specialists.create') }}" wire:navigate class="btn-primary ml-auto">{{ __('crm.specialists.create') }}</a>
    </div>

    @if ($specialists->isEmpty() && ! $showDeleted && $search === '' && $type === '' && ! $location)
        <div class="mt-10 max-w-prose">
            <h2 class="text-lg font-semibold">{{ __('crm.specialists.empty_title') }}</h2>
            <p class="mt-2 text-sm">{{ __('crm.specialists.empty_text') }}</p>
            <a href="{{ route('admin.specialists.create') }}" wire:navigate class="btn-primary mt-5">{{ __('crm.specialists.create') }}</a>
        </div>
    @else
        <div class="panel mt-6 overflow-x-auto">
            <table class="table-base table-stack">
                <thead>
                <tr>
                    <th scope="col">{{ __('crm.fields.name') }}</th>
                    <th scope="col">{{ __('crm.fields.type') }}</th>
                    <th scope="col">{{ __('crm.fields.locations') }}</th>
                    <th scope="col">{{ __('crm.fields.visibility') }}</th>
                    <th scope="col"><span class="sr-only">{{ __('crm.actions.actions') }}</span></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($specialists as $s)
                    <tr wire:key="sp-{{ $s->id }}">
                        <td>
                            <div class="flex items-center gap-3">
                                @if ($s->photo_path)
                                    <img src="{{ Storage::disk('public')->url($s->photo_path) }}" alt="" class="h-10 w-10 rounded-full object-cover">
                                @else
                                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-mist text-sm font-semibold text-ink" aria-hidden="true">{{ mb_substr(preg_replace('/^(Dr\.?\s*)/i', '', $s->display_name), 0, 1) }}</span>
                                @endif
                                <span class="font-medium text-heading">{{ $s->display_name }}</span>
                            </div>
                        </td>
                        <td data-label="{{ __('crm.fields.type') }}">{{ __('crm.specialists.types.'.$s->type) }}</td>
                        <td class="text-sm" data-label="{{ __('crm.fields.locations') }}">{{ $s->locations->where('pivot.is_active', true)->map->name()->join(', ') ?: '—' }}</td>
                        <td data-label="{{ __('crm.fields.visibility') }}">
                            @if (! $s->is_active)
                                <span class="badge-bad">{{ __('crm.state.inactive') }}</span>
                            @elseif ($s->is_public)
                                <x-admin.publish-state :model="$s" />
                            @else
                                <span class="badge-neutral">{{ __('crm.state.hidden') }}</span>
                            @endif
                        </td>
                        <td class="cell-actions text-right">
                            @if ($s->trashed())
                                <button type="button" wire:click="restore({{ $s->id }})" class="btn-link text-sm">{{ __('crm.actions.restore') }}</button>
                            @else
                                <a href="{{ route('admin.specialists.edit', $s) }}" wire:navigate class="btn-link text-sm">{{ __('crm.actions.edit') }}</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-10 text-center text-muted">{{ __('crm.empty_list') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
