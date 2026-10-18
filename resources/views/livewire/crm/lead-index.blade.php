<div class="max-w-6xl">
    <div class="flex flex-wrap items-end gap-4">
        <div class="w-full max-w-xs">
            <label for="search" class="field-label">{{ __('crm.actions.search') }}</label>
            <input id="search" type="search" wire:model.live.debounce.300ms="search" class="input">
        </div>
        <div>
            <label for="status" class="field-label">{{ __('crm.fields.status') }}</label>
            <select id="status" wire:model.live="status" class="input">
                <option value="active">{{ __('leads.filter_active') }}</option>
                @foreach (\App\Models\Lead::STATUSES as $s)<option value="{{ $s }}">{{ __('leads.statuses.'.$s) }} ({{ $counts[$s] ?? 0 }})</option>@endforeach
                <option value="all">{{ __('crm.all') }}</option>
            </select>
        </div>
        <div>
            <label for="type" class="field-label">{{ __('crm.fields.type') }}</label>
            <select id="type" wire:model.live="type" class="input">
                <option value="">{{ __('crm.all') }}</option>
                @foreach (\App\Models\Lead::TYPES as $t)<option value="{{ $t }}">{{ __('leads.types.'.$t) }}</option>@endforeach
            </select>
        </div>
        <label class="flex min-h-11 items-center gap-2 text-sm">
            <input type="checkbox" wire:model.live="mine" class="h-5 w-5 rounded border-line text-ink focus:ring-ink"> {{ __('leads.only_mine') }}
        </label>
    </div>

    <div class="panel mt-6 overflow-x-auto">
        <table class="table-base table-stack">
            <thead><tr>
                <th scope="col">{{ __('leads.person') }}</th>
                <th scope="col">{{ __('crm.fields.type') }}</th>
                <th scope="col">{{ __('crm.fields.status') }}</th>
                <th scope="col">{{ __('leads.received') }}</th>
                <th scope="col">{{ __('leads.assignee') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($leads as $l)
                <tr wire:key="l-{{ $l->id }}">
                    <td><a href="{{ route('crm.leads.show', $l) }}" wire:navigate class="font-medium text-heading hover:underline">{{ $l->name }}</a>
                        <span class="block text-xs text-muted">{{ $l->phone ?? $l->email }} · <span lang="{{ $l->locale }}">{{ strtoupper($l->locale) }}</span></span></td>
                    <td data-label="{{ __('crm.fields.type') }}">{{ __('leads.types.'.$l->type) }}</td>
                    <td data-label="{{ __('crm.fields.status') }}"><span class="{{ match ($l->status) { 'new' => 'badge-warn', 'converted' => 'badge-ok', 'closed' => 'badge-neutral', default => 'badge-info' } }}">{{ __('leads.statuses.'.$l->status) }}</span></td>
                    <td class="whitespace-nowrap" data-label="{{ __('leads.received') }}">{{ $l->created_at->timezone($tz)->format('d.m.Y H:i') }}
                        @if ($l->due_at)<span @class(['block text-xs', 'font-semibold text-bad' => $l->due_at->isPast() && ! $l->closed_at, 'text-muted' => ! ($l->due_at->isPast() && ! $l->closed_at)])>{{ __('tasks.due', ['date' => $l->due_at->timezone($tz)->format('d.m.Y H:i')]) }}</span>@endif</td>
                    <td data-label="{{ __('leads.assignee') }}">{{ $l->assignee?->name ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-10 text-center text-muted">{{ __('crm.empty_list') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $leads->links() }}</div>
</div>
