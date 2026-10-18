@php use App\Domain\Clinical\PrescriptionService as Rx; @endphp
<x-pdf-layout :locale="$locale" :logo="$logo" :tz="$tz" :location="$rx->location" :title="__('clinical.rx.title')">
    <h1>{{ __('clinical.rx.title') }} – {{ __('clinical.rx.types.'.$rx->type) }}</h1>
    <table class="meta">
        <tr><td class="muted">{{ __('crm.fields.patient') }}</td><td><strong>{{ $rx->patient->fullName() }}</strong> ({{ $rx->patient->code }})@if ($rx->patient->birth_date), {{ $rx->patient->birth_date->format('d.m.Y') }}@endif</td></tr>
        <tr><td class="muted">{{ __('clinical.rx.issued_on') }}</td><td>{{ $rx->issued_on->format('d.m.Y') }} · {{ __('clinical.rx.version', ['number' => $rx->version]) }}</td></tr>
        <tr><td class="muted">{{ __('crm.fields.specialist') }}</td><td>{{ $rx->specialist?->display_name }}</td></tr>
        @if ($rx->valid_until)<tr><td class="muted">{{ __('clinical.rx.valid_until') }}</td><td>{{ $rx->valid_until->format('d.m.Y') }}</td></tr>@endif
    </table>
    <h2>{{ __('clinical.sections.refraction') }}</h2>
    <table class="data">
        <tr><th></th>@foreach (Rx::TYPE_FIELDS[$rx->type] as $f)<th>{{ __('clinical.rx.'.$f) }}</th>@endforeach</tr>
        @foreach (['od', 'os'] as $eye)
            <tr><th>{{ __('clinical.rx.'.$eye) }}</th>@foreach (Rx::TYPE_FIELDS[$rx->type] as $f)<td>{{ Rx::display($f, $rx->data[$eye][$f] ?? '') }}</td>@endforeach</tr>
        @endforeach
    </table>
    @if (($rx->data['pd'] ?? '') !== '')<p><strong>{{ __('clinical.rx.pd') }}:</strong> {{ $rx->data['pd'] }}</p>@endif
    @if (($rx->data['use'] ?? '') !== '')<p><strong>{{ __('clinical.rx.use') }}:</strong> {{ __('clinical.rx.uses.'.$rx->data['use']) }}</p>@endif
    @if (($rx->data['notes'] ?? '') !== '')<p><strong>{{ __('clinical.rx.notes') }}:</strong> <span class="pre">{{ $rx->data['notes'] }}</span></p>@endif
    <p style="margin-top: 36px">{{ $rx->specialist?->display_name }}</p>
    <p class="notice">{{ __('patients.pdf.original_language') }}</p>
    <p class="notice">{{ __('patients.pdf.no_qualified_signature') }}</p>
</x-pdf-layout>
