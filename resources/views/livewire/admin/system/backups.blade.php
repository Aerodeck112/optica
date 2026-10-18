@php($tz = config('optica.clinic_timezone'))
<div class="max-w-5xl">
    <p class="max-w-prose text-sm text-muted">{{ __('system.backups.intro') }}</p>
    <div aria-live="polite">
        @if ($notice)<div class="mt-4 rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ $notice }}</div>@endif
        @if ($error)<div class="mt-4 rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad" role="alert">{{ $error }}</div>@endif
        @if ($lastError)<div class="mt-4 rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad" role="alert">{{ __('system.backups.last_error', ['date' => \Carbon\CarbonImmutable::parse($lastError['at'])->setTimezone($tz)->format('d.m.Y H:i')]) }}</div>@endif
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <section class="panel overflow-x-auto" aria-labelledby="bk-list">
            <div class="flex flex-wrap items-center gap-3 border-b border-line px-4 py-3">
                <h2 id="bk-list" class="flex-1 text-base font-semibold text-heading">{{ __('system.backups.list') }}</h2>
                <button type="button" class="btn-primary" wire:click="create" wire:loading.attr="disabled" wire:target="create">
                    <span wire:loading.remove wire:target="create">{{ __('system.backups.create') }}</span>
                    <span wire:loading wire:target="create">{{ __('system.backups.creating') }}</span>
                </button>
            </div>
            <table class="table-base table-stack">
                <thead><tr>
                    <th scope="col">{{ __('system.backups.date') }}</th>
                    <th scope="col">{{ __('system.backups.type') }}</th>
                    <th scope="col">{{ __('system.backups.content') }}</th>
                    <th scope="col"><span class="sr-only">{{ __('crm.actions.actions') }}</span></th>
                </tr></thead>
                <tbody>
                @forelse ($backups as $b)
                    <tr wire:key="bk-{{ $b['name'] }}">
                        <td><span class="font-medium text-heading">{{ \Carbon\CarbonImmutable::parse($b['created_at'])->setTimezone($tz)->format('d.m.Y H:i') }}</span>
                            <span class="block text-xs text-muted">v{{ $b['version'] }} · {{ number_format($b['size'] / 1048576, 1, ',', '.') }} MB</span></td>
                        <td data-label="{{ __('system.backups.type') }}">{{ __('system.backups.types.'.$b['type']) }}
                            @if (! ($b['verified'] ?? false))<span class="badge-bad">{{ __('system.backups.unverified') }}</span>@endif</td>
                        <td data-label="{{ __('system.backups.content') }}" class="text-xs text-muted">
                            {{ trans_choice('system.backups.tables', $b['database']['tables'], ['count' => $b['database']['tables']]) }} · {{ number_format($b['database']['rows'], 0, ',', '.') }} {{ __('system.backups.rows') }}
                            @if (isset($b['parts']['files.zip']) || isset($b['parts']['files.zip.enc'])) · {{ __('system.backups.with_files') }}@endif
                            @if ($b['encrypted'] ?? false) · {{ __('system.backups.encrypted') }}@endif
                        </td>
                        <td class="cell-actions whitespace-nowrap text-right">
                            <a href="{{ route('admin.system.backups.download', $b['name']) }}" class="btn-link text-sm">{{ __('system.backups.download') }}</a>
                            <button type="button" class="btn-link ml-3 text-sm" wire:click="verify('{{ $b['name'] }}')">{{ __('system.backups.verify') }}</button>
                            <button type="button" class="btn-link ml-3 text-sm" wire:click="askRestore('{{ $b['name'] }}')">{{ __('system.backups.restore') }}</button>
                            <button type="button" class="btn-link ml-3 text-sm text-muted" wire:click="delete('{{ $b['name'] }}')" wire:confirm="{{ __('system.backups.confirm_delete') }}">{{ __('system.backups.delete') }}</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-10 text-center text-sm text-muted">{{ __('system.backups.none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>

        <section class="panel h-fit p-4" aria-labelledby="bk-schedule">
            <h2 id="bk-schedule" class="text-base font-semibold text-heading">{{ __('system.backups.schedule') }}</h2>
            <p class="mt-1 text-xs text-muted">{{ __('system.backups.schedule_hint', ['keep' => config('optica.backups.keep')]) }}</p>
            <form wire:submit="saveSchedule" class="mt-3 space-y-3">
                <label class="flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" wire:model="scheduleEnabled" class="h-5 w-5 rounded border-line"> {{ __('system.backups.schedule_enabled') }}</label>
                <div>
                    <label for="bk-time" class="field-label">{{ __('system.backups.schedule_time') }}</label>
                    <input id="bk-time" type="time" wire:model="scheduleTime" class="input">
                    @error('scheduleTime')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="btn-secondary">{{ __('crm.actions.save') }}</button>
            </form>
        </section>
    </div>

    @if ($restoring)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-ink-900/50 p-4 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="bk-restore">
            <form wire:submit="restore" class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-2xl">
                <h2 id="bk-restore" class="text-lg font-semibold text-heading">{{ __('system.backups.restore_title') }}</h2>
                <p class="mt-2 text-sm">{{ __('system.backups.restore_warning', ['date' => \Carbon\CarbonImmutable::parse(\App\Domain\System\BackupManager::make()->manifest($restoring)['created_at'])->setTimezone($tz)->format('d.m.Y H:i')]) }}</p>
                <label for="bk-confirm" class="field-label mt-4">{{ __('system.backups.confirm_label', ['word' => __('system.backups.confirm_word')]) }}</label>
                <input id="bk-confirm" wire:model="confirmation" autocomplete="off" class="input" autofocus>
                @error('confirmation')<p class="field-error">{{ $message }}</p>@enderror
                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ __('system.backups.restore') }}</button>
                    <button type="button" class="btn-secondary" wire:click="$set('restoring', null)">{{ __('crm.actions.cancel') }}</button>
                </div>
            </form>
        </div>
    @endif
</div>
