<div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
    <div class="space-y-6">
        @if (! $editing)
            <section class="panel p-5" aria-labelledby="pd-title">
                <div class="flex items-center gap-3">
                    <h2 id="pd-title" class="flex-1 text-base font-semibold">{{ __('patients.personal_data') }}</h2>
                    @can('patients.update')<button type="button" class="btn-secondary" wire:click="edit">{{ __('crm.actions.edit') }}</button>@endcan
                </div>
                <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    @foreach ([
                        'last_name' => $patient->last_name, 'first_name' => $patient->first_name,
                        'birth_date' => $patient->birth_date?->format('d.m.Y'), 'sex' => $patient->sex ? __('patients.sexes.'.$patient->sex) : null,
                        'address' => trim(($patient->address ?? '').($patient->city ? ', '.$patient->city : ''), ', '),
                        'primary_location_id' => $patient->primaryLocation?->name(),
                        'legal_guardian_id' => $patient->legalGuardian?->fullName(),
                        'source' => $patient->source ? __('patients.sources.'.$patient->source) : null,
                    ] as $field => $value)
                        <div>
                            <dt class="text-xs text-muted">{{ __('patients.fields.'.$field) }}</dt>
                            <dd class="text-heading">
                                @if ($field === 'legal_guardian_id' && $patient->legalGuardian)
                                    <a href="{{ route('crm.patients.show', $patient->legalGuardian) }}" wire:navigate class="btn-link">{{ $value }}</a>
                                @else
                                    {{ $value ?: '—' }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                    @can('patients.view_sensitive')
                        <div>
                            <dt class="text-xs text-muted">CNP</dt>
                            <dd class="text-heading">
                                @if (! $patient->cnp_hash)
                                    —
                                @elseif ($cnpVisible)
                                    <span class="font-mono">{{ $patient->cnp() }}</span>
                                @else
                                    <span class="font-mono">•••••••••••••</span>
                                    <button type="button" class="btn-link ml-2 text-xs" wire:click="revealCnp">{{ __('patients.reveal') }}</button>
                                @endif
                            </dd>
                        </div>
                    @endcan
                </dl>
                @if ($patient->admin_notes)
                    <div class="mt-4 border-t border-line pt-4">
                        <p class="text-xs text-muted">{{ __('patients.fields.admin_notes') }}</p>
                        <p class="mt-1 whitespace-pre-line text-sm">{{ $patient->admin_notes }}</p>
                    </div>
                @endif
            </section>
        @else
            <form wire:submit="save" class="panel space-y-5 p-5" data-preserve>
                <h2 class="text-base font-semibold">{{ __('patients.personal_data') }}</h2>
                <div class="grid gap-5 md:grid-cols-2">
                    <x-admin.text model="form.last_name" :label="__('patients.fields.last_name')" required />
                    <x-admin.text model="form.first_name" :label="__('patients.fields.first_name')" required />
                    <x-admin.text model="form.birth_date" type="date" :label="__('patients.fields.birth_date')" />
                    <div>
                        <label for="sex" class="field-label">{{ __('patients.fields.sex') }}</label>
                        <select id="sex" wire:model="form.sex" class="input">
                            <option value="">—</option>
                            @foreach (['female', 'male', 'other'] as $s)<option value="{{ $s }}">{{ __('patients.sexes.'.$s) }}</option>@endforeach
                        </select>
                    </div>
                    <x-admin.text model="form.phone" type="tel" :label="__('crm.fields.phone')" :hint="__('patients.phone_hint')" />
                    <x-admin.text model="form.email" type="email" :label="__('crm.fields.email')" />
                    <div>
                        <label for="plocale" class="field-label">{{ __('patients.fields.locale') }}</label>
                        <select id="plocale" wire:model="form.locale" class="input">
                            @foreach (config('optica.locales') as $l)<option value="{{ $l }}">{{ __('crm.languages.'.$l) }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label for="ploc" class="field-label">{{ __('patients.fields.primary_location_id') }}</label>
                        <select id="ploc" wire:model="form.primary_location_id" class="input">
                            <option value="">—</option>
                            @foreach ($locations as $l)<option value="{{ $l->id }}">{{ $l->name() }}</option>@endforeach
                        </select>
                    </div>
                    <x-admin.text model="form.address" :label="__('patients.fields.address')" />
                    <x-admin.text model="form.city" :label="__('patients.fields.city')" />
                    @can('patients.view_sensitive')
                        <x-admin.text model="cnp" inputmode="numeric" maxlength="13" autocomplete="off" :label="__('patients.cnp_label')" :hint="__('patients.cnp_hint')" />
                    @endcan
                </div>
                <div>
                    <p class="field-label">{{ __('patients.fields.legal_guardian_id') }}</p>
                    @if ($guardianChoice)
                        <p class="text-sm">{{ $guardianChoice->fullName() }} <span class="text-muted">· {{ $guardianChoice->code }}</span>
                            <button type="button" class="btn-link ml-2 text-xs text-bad" wire:click="$set('form.legal_guardian_id', null)">{{ __('cms.media.remove') }}</button></p>
                    @else
                        <label for="guardian-q" class="sr-only">{{ __('patients.search_guardian') }}</label>
                        <input id="guardian-q" type="search" class="input max-w-md" wire:model.live.debounce.300ms="guardianQuery" placeholder="{{ __('patients.search_guardian') }}" autocomplete="off">
                        @if ($guardianResults->isNotEmpty())
                        <ul class="mt-2 max-w-md divide-y divide-line rounded-lg ring-1 ring-line" role="list">
                            @foreach ($guardianResults as $r)
                                <li><button type="button" class="block min-h-11 w-full px-3 py-2 text-left text-sm hover:bg-mist" wire:click="chooseGuardian({{ $r->id }})">{{ $r->fullName() }} <span class="text-muted">· {{ $r->birth_date?->format('d.m.Y') }} · {{ $r->phone }}</span></button></li>
                            @endforeach
                        </ul>
                        @endif
                    @endif
                    @error('form.legal_guardian_id')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="notes" class="field-label">{{ __('patients.fields.admin_notes') }}</label>
                    <textarea id="notes" wire:model="form.admin_notes" rows="3" class="input" aria-describedby="notes-hint"></textarea>
                    <p id="notes-hint" class="field-hint">{{ __('patients.admin_notes_hint') }}</p>
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="btn-primary">{{ __('crm.actions.save') }}</button>
                    <button type="button" class="btn-secondary" wire:click="$set('editing', false)">{{ __('crm.actions.cancel') }}</button>
                </div>
            </form>
        @endif

        <section class="panel p-5" aria-labelledby="family-title">
            <h2 id="family-title" class="text-base font-semibold">{{ __('patients.family') }}</h2>
            <ul class="mt-3 divide-y divide-line text-sm" role="list">
                @foreach ($patient->relations as $rel)
                    <li class="flex items-center gap-3 py-2">
                        <span class="flex-1"><a href="{{ route('crm.patients.show', $rel->related_patient_id) }}" wire:navigate class="btn-link">{{ $rel->relatedPatient?->fullName() }}</a>
                            <span class="text-muted">· {{ __('patients.relations.'.$rel->relation) }}</span></span>
                        @can('patients.update')<button type="button" class="btn-link text-xs text-bad" wire:click="removeRelation({{ $rel->id }})">{{ __('cms.media.remove') }}</button>@endcan
                    </li>
                @endforeach
                @foreach ($relatedTo as $rel)
                    <li class="py-2"><a href="{{ route('crm.patients.show', $rel->patient_id) }}" wire:navigate class="btn-link">{{ $rel->patient?->fullName() }}</a>
                        <span class="text-muted">· {{ __('patients.relations_inverse.'.$rel->relation) }}</span></li>
                @endforeach
                @foreach ($dependants as $d)
                    <li class="py-2"><a href="{{ route('crm.patients.show', $d) }}" wire:navigate class="btn-link">{{ $d->fullName() }}</a>
                        <span class="text-muted">· {{ __('patients.guardian_of') }}</span></li>
                @endforeach
                @if ($patient->relations->isEmpty() && $relatedTo->isEmpty() && $dependants->isEmpty())
                    <li class="py-2 text-muted">{{ __('patients.no_family') }}</li>
                @endif
            </ul>
            @can('patients.update')
                <div class="mt-4 flex flex-wrap items-end gap-3 border-t border-line pt-4">
                    <div class="w-full max-w-xs">
                        <label for="rel-q" class="field-label">{{ __('patients.add_relation') }}</label>
                        <input id="rel-q" type="search" class="input" wire:model.live.debounce.300ms="relationQuery" placeholder="{{ __('patients.search_placeholder') }}" autocomplete="off">
                    </div>
                    <div>
                        <label for="rel-type" class="field-label">{{ __('patients.relation_type') }}</label>
                        <select id="rel-type" wire:model="relationType" class="input">
                            @foreach (\App\Models\PatientRelation::RELATIONS as $r)<option value="{{ $r }}">{{ __('patients.relations.'.$r) }}</option>@endforeach
                        </select>
                    </div>
                </div>
                @if ($relationResults->isNotEmpty())
                <ul class="mt-2 max-w-md divide-y divide-line rounded-lg ring-1 ring-line" role="list">
                    @foreach ($relationResults as $r)
                        <li><button type="button" class="block min-h-11 w-full px-3 py-2 text-left text-sm hover:bg-mist" wire:click="addRelation({{ $r->id }})">{{ $r->fullName() }} <span class="text-muted">· {{ $r->birth_date?->format('d.m.Y') }} · {{ $r->phone }}</span></button></li>
                    @endforeach
                </ul>
                @endif
            @endcan
        </section>
    </div>

    <aside class="space-y-6">
        @can('patients.merge')
            <livewire:crm.patient.merge-panel :patient-id="$patient->id" :key="'merge-'.$patient->id" />
        @endcan
        @can('patients.archive')
            <section class="panel p-4 text-sm">
                <p class="text-muted">{{ $patient->archived_at ? __('patients.archived_hint') : __('patients.archive_hint') }}</p>
                <button type="button" class="btn-secondary mt-3 w-full" wire:click="archive" wire:confirm="{{ $patient->archived_at ? __('patients.confirm_unarchive') : __('patients.confirm_archive') }}">
                    {{ $patient->archived_at ? __('patients.unarchive') : __('patients.archive') }}</button>
            </section>
        @endcan
    </aside>
</div>
