<div class="space-y-6">
    <div aria-live="polite">
        @if ($notice)<div class="rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ $notice }}</div>@endif
    </div>
    @can('documents.upload')
        <form wire:submit="upload" class="panel space-y-4 p-5">
            <h2 class="text-base font-semibold">{{ __('patients.documents.upload') }}</h2>
            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <label for="doc-file" class="field-label">{{ __('patients.documents.file') }}</label>
                    <input id="doc-file" type="file" wire:model="file" accept="application/pdf,image/jpeg,image/png,image/webp" class="block w-full text-sm" aria-describedby="doc-file-hint">
                    <p id="doc-file-hint" class="field-hint">{{ __('patients.documents.file_hint') }}</p>
                    <p wire:loading wire:target="file" class="field-hint">{{ __('crm.uploading') }}</p>
                    @error('file')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <x-admin.text model="title" :label="__('patients.documents.title_field')" required />
                <div>
                    <label for="doc-cat" class="field-label">{{ __('patients.documents.category') }}</label>
                    <select id="doc-cat" wire:model="category" class="input">
                        @foreach (\App\Models\PatientDocument::CATEGORIES as $c)<option value="{{ $c }}">{{ __('patients.documents.categories.'.$c) }}</option>@endforeach
                    </select>
                </div>
            </div>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ __('patients.documents.save') }}</button>
        </form>
    @endcan

    <div class="panel overflow-x-auto">
        <table class="table-base table-stack">
            <thead><tr>
                <th scope="col">{{ __('patients.documents.title_field') }}</th>
                <th scope="col">{{ __('patients.documents.category') }}</th>
                <th scope="col">{{ __('patients.documents.added') }}</th>
                <th scope="col"><span class="sr-only">{{ __('crm.actions.actions') }}</span></th>
            </tr></thead>
            <tbody>
            @forelse ($documents as $d)
                <tr wire:key="d-{{ $d->id }}">
                    <td><span class="font-medium text-heading">{{ $d->title }}</span>
                        <span class="block text-xs text-muted">{{ strtoupper(\App\Domain\Documents\DocumentVault::MIMES[$d->mime] ?? '') }} · {{ number_format($d->size / 1024, 0, ',', '.') }} KB</span></td>
                    <td data-label="{{ __('patients.documents.category') }}">{{ __('patients.documents.categories.'.$d->category) }}</td>
                    <td class="whitespace-nowrap" data-label="{{ __('patients.documents.added') }}">{{ $d->created_at->timezone($tz)->format('d.m.Y H:i') }}<span class="block text-xs text-muted">{{ $d->uploader?->name }}</span></td>
                    <td class="cell-actions whitespace-nowrap text-right">
                        <a href="{{ route('crm.documents.download', $d) }}" class="btn-link text-sm">{{ __('patients.documents.download') }}</a>
                        @can('documents.delete')
                            <button type="button" class="btn-link ml-3 text-sm text-bad" wire:click="delete({{ $d->id }})" wire:confirm="{{ __('patients.documents.confirm_delete') }}">{{ __('patients.documents.delete') }}</button>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-8 text-center text-muted">{{ __('patients.documents.none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
