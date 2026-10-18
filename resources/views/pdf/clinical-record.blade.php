@php use App\Domain\Clinical\TemplateSchema as T; $fields = collect(T::fields($record->template)); @endphp
<x-pdf-layout :locale="$locale" :logo="$logo" :tz="$tz" :location="$record->location" :title="$record->template->label($locale)">
    <h1>{{ $record->template->label($locale) }}</h1>
    <table class="meta">
        <tr><td class="muted">{{ __('crm.fields.patient') }}</td><td><strong>{{ $record->patient->fullName() }}</strong> ({{ $record->patient->code }})@if ($record->patient->birth_date), {{ $record->patient->birth_date->format('d.m.Y') }}@endif</td></tr>
        <tr><td class="muted">{{ __('patients.records.date') }}</td><td>{{ $record->examined_at->timezone($tz)->format('d.m.Y H:i') }}</td></tr>
        <tr><td class="muted">{{ __('crm.fields.specialist') }}</td><td>{{ $record->specialist?->display_name }}</td></tr>
        <tr><td class="muted">{{ __('crm.fields.status') }}</td><td>{{ __('clinical.status.'.$record->status) }}</td></tr>
    </table>

    @foreach ($fields->groupBy(fn ($f) => $f['section'] ?? 'other') as $section => $group)
        <h2>{{ __('clinical.sections.'.$section) }}</h2>
        @php($eyes = $group->filter(fn ($f) => $f['eyes'] ?? false))
        @if ($eyes->isNotEmpty())
            <table class="data">
                <tr><th></th><th>OD</th><th>OS</th></tr>
                @foreach ($eyes as $f)
                    <tr><th>{{ T::label($f, $locale) }}</th>
                        <td>{{ T::display($f, $record->data[$f['key']]['od'] ?? '') }}</td>
                        <td>{{ T::display($f, $record->data[$f['key']]['os'] ?? '') }}</td></tr>
                @endforeach
            </table>
        @endif
        @foreach ($group->reject(fn ($f) => $f['eyes'] ?? false) as $f)
            <p><strong>{{ T::label($f, $locale) }}:</strong> <span class="pre">{{ T::display($f, $record->data[$f['key']] ?? '') }}</span></p>
        @endforeach
    @endforeach

    @if ($record->amendments->isNotEmpty())
        <h2>{{ __('patients.records.amendments') }}</h2>
        @foreach ($record->amendments as $a)
            <p class="muted">{{ $a->created_at->timezone($tz)->format('d.m.Y H:i') }} · {{ $a->user?->name }} · {{ $a->reason }}</p>
        @endforeach
    @endif
    <p class="notice">{{ __('patients.pdf.original_language') }}</p>
    <p class="notice">{{ __('patients.pdf.no_qualified_signature') }}</p>
</x-pdf-layout>
