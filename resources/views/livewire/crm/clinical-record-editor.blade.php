@php use App\Domain\Clinical\TemplateSchema as T; @endphp
<div class="max-w-5xl">
    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('crm.patients.show', ['patient' => $record->patient_id, 'tab' => 'records']) }}" wire:navigate class="btn-link text-sm">← {{ $record->patient->fullName() }}</a>
        <span class="{{ $record->isFinal() ? 'badge-ok' : 'badge-warn' }}">{{ __('clinical.status.'.$record->status) }}</span>
        <div class="ml-auto flex flex-wrap gap-3 text-sm">
            @foreach (config('optica.locales') as $l)
                <a href="{{ route('crm.records.pdf', ['record' => $record, 'lang' => $l]) }}" target="_blank" rel="noopener" class="btn-link">PDF {{ strtoupper($l) }}</a>
            @endforeach
        </div>
    </div>
    <p class="mt-2 text-sm text-muted">
        {{ $record->template->label() }} (v{{ $record->template->version }}) · {{ $record->specialist?->display_name }} · {{ $record->location?->name() }} ·
        {{ $record->examined_at->timezone($tz)->format('d.m.Y H:i') }}
        @if ($record->appointment) · {{ $record->appointment->service?->name() }}@endif
    </p>
    @if ($record->isFinal())
        <p class="mt-1 text-xs text-muted">{{ __('patients.records.finalized_by', ['name' => $record->finalizer?->name, 'date' => $record->finalized_at->timezone($tz)->format('d.m.Y H:i')]) }}</p>
    @endif

    <div class="mt-4" aria-live="polite">
        @if ($notice)<div class="rounded-lg bg-ok-bg px-4 py-3 text-sm text-ok" role="status">{{ $notice }}</div>@endif
        @if ($failure)
            <div class="flex flex-wrap items-center gap-3 rounded-lg bg-bad-bg px-4 py-3 text-sm text-bad" role="alert">
                <p class="flex-1">{{ $failure }}</p>
                @if ($failure === __('clinical.errors.conflict'))<button type="button" class="btn-secondary" wire:click="reload">{{ __('cms.pages.reload') }}</button>@endif
            </div>
        @endif
    </div>

    <form wire:submit="save" class="mt-6 space-y-6" data-preserve>
        @foreach ($sections as $section => $fields)
            <fieldset class="panel p-5">
                <legend class="sr-only">{{ __('clinical.sections.'.$section) }}</legend>
                <h2 class="text-base font-semibold" aria-hidden="true">{{ __('clinical.sections.'.$section) }}</h2>
                <div class="mt-4 grid gap-5 md:grid-cols-2">
                    @foreach ($fields as $field)
                        @php($key = $field['key'])
                        @php($label = T::label($field))
                        @php($unit = ($field['unit'] ?? '') !== '' ? ' ('.$field['unit'].')' : '')
                        @php($range = isset($field['min'], $field['max']) ? __('patients.records.range', ['min' => $field['min'], 'max' => $field['max'], 'step' => $field['step'] ?? '']) : null)
                        <div @class(['md:col-span-2' => $field['type'] === 'text'])>
                            @if ($field['eyes'] ?? false)
                                <fieldset>
                                    <legend class="field-label">{{ $label }}{{ $unit }}@if ($field['required'] ?? false) <span class="text-bad" aria-hidden="true">*</span>@endif</legend>
                                    <div class="grid grid-cols-2 gap-3">
                                        @foreach (['od', 'os'] as $eye)
                                            @php($path = "data.$key.$eye")
                                            <div>
                                                <label for="f-{{ $key }}-{{ $eye }}" class="mb-1 block text-xs font-medium text-muted">{{ strtoupper($eye) }}</label>
                                                @if ($editable)
                                                    <input id="f-{{ $key }}-{{ $eye }}" type="text" inputmode="decimal" wire:model.blur="{{ $path }}" class="input tabular-nums"
                                                           @error($path) aria-invalid="true" aria-describedby="e-{{ $key }}-{{ $eye }}" @enderror>
                                                @else
                                                    <p id="f-{{ $key }}-{{ $eye }}" class="min-h-11 py-2.5 text-sm tabular-nums">{{ T::display($field, $data[$key][$eye] ?? '') }}</p>
                                                @endif
                                                @error($path)<p id="e-{{ $key }}-{{ $eye }}" class="field-error">{{ $message }}</p>@enderror
                                            </div>
                                        @endforeach
                                    </div>
                                    @if ($range && $editable)<p class="field-hint">{{ $range }}</p>@endif
                                </fieldset>
                            @else
                                @php($path = "data.$key")
                                <label for="f-{{ $key }}" class="field-label">{{ $label }}{{ $unit }}@if ($field['required'] ?? false) <span class="text-bad" aria-hidden="true">*</span>@endif</label>
                                @if (! $editable)
                                    <p id="f-{{ $key }}" class="whitespace-pre-line text-sm">{{ T::display($field, $data[$key] ?? '') }}</p>
                                @elseif ($field['type'] === 'text')
                                    <textarea id="f-{{ $key }}" wire:model.blur="{{ $path }}" rows="3" class="input" @error($path) aria-invalid="true" @enderror></textarea>
                                @elseif ($field['type'] === 'date')
                                    <input id="f-{{ $key }}" type="date" wire:model.blur="{{ $path }}" class="input" @error($path) aria-invalid="true" @enderror>
                                @elseif ($field['type'] === 'choice')
                                    <select id="f-{{ $key }}" wire:model="{{ $path }}" class="input">
                                        <option value="">—</option>
                                        @foreach ($field['options'] ?? [] as $o)<option value="{{ $o['value'] }}">{{ T::label($o) }}</option>@endforeach
                                    </select>
                                @else
                                    <input id="f-{{ $key }}" type="text" @if ($field['type'] === 'number') inputmode="decimal" @endif wire:model.blur="{{ $path }}" class="input" @error($path) aria-invalid="true" @enderror>
                                @endif
                                @if ($range && $editable)<p class="field-hint">{{ $range }}</p>@endif
                                @error($path)<p class="field-error">{{ $message }}</p>@enderror
                            @endif
                        </div>
                    @endforeach
                </div>
            </fieldset>
        @endforeach

        <p class="text-xs text-muted">{{ __('patients.records.no_automation') }}</p>

        @if ($amending)
            <div class="panel space-y-3 p-5">
                <label for="reason" class="field-label">{{ __('patients.records.amend_reason') }} <span class="text-bad" aria-hidden="true">*</span></label>
                <textarea id="reason" wire:model="reason" rows="2" class="input" required></textarea>
                <div class="flex flex-wrap gap-3">
                    <button type="button" class="btn-primary" wire:click="amend">{{ __('patients.records.save_amendment') }}</button>
                    <button type="button" class="btn-secondary" wire:click="cancelAmend">{{ __('crm.actions.cancel') }}</button>
                </div>
            </div>
        @elseif (! $record->isFinal() && $editable)
            <div class="sticky bottom-0 -mx-4 flex flex-wrap items-center gap-3 border-t border-line bg-paper/95 px-4 py-4 backdrop-blur sm:-mx-6 sm:px-6 lg:static lg:mx-0 lg:border-0 lg:bg-transparent lg:px-0">
                <button type="submit" class="btn-secondary" wire:loading.attr="disabled">{{ __('patients.records.save_draft') }}</button>
                @if ($canFinalize)
                    <button type="button" class="btn-primary" wire:click="finalize" wire:confirm="{{ __('patients.records.confirm_finalize') }}" wire:loading.attr="disabled">{{ __('patients.records.finalize') }}</button>
                @endif
            </div>
        @elseif ($canAmend)
            <button type="button" class="btn-secondary" wire:click="startAmend">{{ __('patients.records.start_amend') }}</button>
        @endif
    </form>

    @if ($record->amendments->isNotEmpty())
        <section class="mt-10" aria-labelledby="amend-title">
            <h2 id="amend-title" class="text-base font-semibold">{{ __('patients.records.amendments') }}</h2>
            <ol class="mt-3 space-y-3" role="list">
                @foreach ($record->amendments as $a)
                    <li class="panel p-4 text-sm">
                        <p class="font-medium">{{ $a->created_at->timezone($tz)->format('d.m.Y H:i') }} · {{ $a->user?->name }}</p>
                        <p class="mt-1 text-muted">{{ __('patients.records.reason', ['reason' => $a->reason]) }}</p>
                        <ul class="mt-2 space-y-1">
                            @foreach ($a->changes as $key => $change)
                                @php($f = $fieldsByKey[$key] ?? ['key' => $key, 'type' => 'text', 'label' => ['ro' => $key]])
                                <li><span class="font-medium">{{ T::label($f) }}:</span>
                                    @if (is_array($change['old']))
                                        @foreach (['od', 'os'] as $eye)
                                            @if (($change['old'][$eye] ?? '') !== ($change['new'][$eye] ?? ''))
                                                {{ strtoupper($eye) }} <del class="text-muted">{{ T::display($f, $change['old'][$eye] ?? '') }}</del> → <ins class="no-underline">{{ T::display($f, $change['new'][$eye] ?? '') }}</ins>
                                            @endif
                                        @endforeach
                                    @else
                                        <del class="text-muted">{{ T::display($f, $change['old']) }}</del> → <ins class="no-underline">{{ T::display($f, $change['new']) }}</ins>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif
</div>
