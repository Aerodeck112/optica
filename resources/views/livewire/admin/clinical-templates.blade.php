<div class="max-w-6xl">
    <p class="max-w-prose text-sm text-muted">{{ __('admin_templates.intro') }}</p>
    <div aria-live="polite">
        @if ($notice)<div class="mt-4 rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ $notice }}</div>@endif
    </div>

    <div class="panel mt-6 overflow-x-auto">
        <table class="table-base table-stack">
            <thead><tr>
                <th scope="col">{{ __('admin_templates.template') }}</th>
                <th scope="col">{{ __('crm.fields.status') }}</th>
                <th scope="col">{{ __('admin_templates.validated_by') }}</th>
                <th scope="col"><span class="sr-only">{{ __('crm.actions.actions') }}</span></th>
            </tr></thead>
            <tbody>
            @foreach ($templates as $t)
                <tr wire:key="t-{{ $t->id }}">
                    <td><span class="font-medium text-heading">{{ $t->label() }}</span> <span class="text-xs text-muted">v{{ $t->version }} · {{ count($t->fields ?? []) }} {{ __('admin_templates.fields_count') }}</span></td>
                    <td data-label="{{ __('crm.fields.status') }}"><span class="{{ match ($t->status) { 'validated' => 'badge-ok', 'draft' => 'badge-warn', default => 'badge-neutral' } }}">{{ __('clinical.template_status.'.$t->status) }}</span></td>
                    <td data-label="{{ __('admin_templates.validated_by') }}">@if ($t->validated_at){{ $t->validator?->name }} <span class="block text-xs text-muted">{{ $t->validated_at->timezone(config('optica.clinic_timezone'))->format('d.m.Y H:i') }}</span>@else — @endif</td>
                    <td class="cell-actions whitespace-nowrap text-right">
                        @if ($t->status === 'draft')
                            <button type="button" class="btn-link text-sm" wire:click="edit({{ $t->id }})">{{ __('admin_templates.review') }}</button>
                        @elseif ($t->status === 'validated')
                            <button type="button" class="btn-link text-sm" wire:click="newVersion({{ $t->id }})">{{ __('admin_templates.new_version') }}</button>
                            <button type="button" class="btn-link ml-3 text-sm text-muted" wire:click="retire({{ $t->id }})" wire:confirm="{{ __('admin_templates.confirm_retire') }}">{{ __('admin_templates.retire') }}</button>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if ($editing)
        <form wire:submit="save" class="mt-8 space-y-6">
            <h2 class="text-base font-semibold">{{ __('admin_templates.editing') }}</h2>
            <div class="grid gap-5 md:grid-cols-2">
                <x-admin.text model="name.ro" :label="__('admin_templates.name').' (RO)'" required />
                <x-admin.text model="name.hu" :label="__('admin_templates.name').' (HU)'" />
            </div>
            <ol class="space-y-3" role="list">
                @foreach ($fields as $i => $f)
                    <li class="panel p-4" wire:key="field-{{ $i }}">
                        <div class="grid gap-3 md:grid-cols-6">
                            <x-admin.text :model="'fields.'.$i.'.label.ro'" :label="__('admin_templates.label').' (RO)'" />
                            <x-admin.text :model="'fields.'.$i.'.label.hu'" :label="__('admin_templates.label').' (HU)'" />
                            <x-admin.text :model="'fields.'.$i.'.key'" :label="__('admin_templates.key')" />
                            <div>
                                <label for="sec-{{ $i }}" class="field-label">{{ __('admin_templates.section') }}</label>
                                <select id="sec-{{ $i }}" wire:model="fields.{{ $i }}.section" class="input">
                                    @foreach (\App\Domain\Clinical\TemplateSchema::SECTIONS as $s)<option value="{{ $s }}">{{ __('clinical.sections.'.$s) }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label for="type-{{ $i }}" class="field-label">{{ __('crm.fields.type') }}</label>
                                <select id="type-{{ $i }}" wire:model.live="fields.{{ $i }}.type" class="input">
                                    @foreach (\App\Domain\Clinical\TemplateSchema::TYPES as $t)<option value="{{ $t }}">{{ __('admin_templates.types.'.$t) }}</option>@endforeach
                                </select>
                            </div>
                            @if ($f['type'] === 'number')
                                <x-admin.text :model="'fields.'.$i.'.min'" label="Min" inputmode="decimal" />
                                <x-admin.text :model="'fields.'.$i.'.max'" label="Max" inputmode="decimal" />
                                <x-admin.text :model="'fields.'.$i.'.step'" :label="__('admin_templates.step')" inputmode="decimal" />
                                <x-admin.text :model="'fields.'.$i.'.unit'" :label="__('admin_templates.unit')" />
                            @endif
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-2">
                            @if (in_array($f['type'], ['number', 'line'], true))<x-admin.toggle :model="'fields.'.$i.'.eyes'" :label="__('admin_templates.eyes')" />@endif
                            @if ($f['type'] === 'number')<x-admin.toggle :model="'fields.'.$i.'.signed'" :label="__('admin_templates.signed')" />@endif
                            <x-admin.toggle :model="'fields.'.$i.'.required'" :label="__('admin_templates.required')" />
                            <div class="ml-auto flex gap-1">
                                <button type="button" class="btn-secondary min-h-10 px-2.5" wire:click="moveField({{ $i }}, -1)" @disabled($loop->first) aria-label="{{ __('admin_templates.move_up') }}">↑</button>
                                <button type="button" class="btn-secondary min-h-10 px-2.5" wire:click="moveField({{ $i }}, 1)" @disabled($loop->last) aria-label="{{ __('admin_templates.move_down') }}">↓</button>
                                <button type="button" class="btn-danger min-h-10 px-3" wire:click="removeField({{ $i }})">{{ __('admin_templates.remove') }}</button>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
            @error('fields')<p class="field-error">{{ $message }}</p>@enderror
            <button type="button" class="btn-secondary" wire:click="addField">{{ __('admin_templates.add_field') }}</button>
            <div class="flex flex-wrap gap-3 border-t border-line pt-6">
                <button type="submit" class="btn-secondary">{{ __('crm.actions.save') }}</button>
                <button type="button" class="btn-primary" wire:click="approve({{ $editing }})" wire:confirm="{{ __('admin_templates.confirm_validate') }}">{{ __('admin_templates.validate') }}</button>
            </div>
            <p class="text-xs text-muted">{{ __('admin_templates.validate_hint') }}</p>
        </form>
    @endif
</div>
