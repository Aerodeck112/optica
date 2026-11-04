<!doctype html>
<html lang="{{ $waiver->locale }}">
<head>
<meta charset="utf-8">
<title>Declarație {{ $waiver->participant_name }}</title>
<style>
  body { font-family: system-ui, sans-serif; color: #111; max-width: 190mm; margin: 10mm auto; font-size: 10.5pt; line-height: 1.45; }
  h1 { font-size: 15pt; margin: 0 0 2mm; } h2 { font-size: 11.5pt; margin: 5mm 0 1mm; }
  .meta { color: #555; font-size: 9pt; } table { width: 100%; border-collapse: collapse; margin: 4mm 0; }
  td { border: 1px solid #ccc; padding: 1.5mm 2.5mm; vertical-align: top; } td:first-child { width: 45mm; color: #555; }
  .sig img { max-height: 30mm; border: 1px solid #ccc; } .print-bar { text-align: center; margin-bottom: 6mm; }
  @media print { .print-bar { display: none; } body { margin: 0 auto; } }
</style>
</head>
<body>
<div class="print-bar"><button onclick="window.print()">Tipărește</button></div>
<h1>{{ $document?->getTranslation('title', $waiver->locale) ?? 'Declarație de participare' }}</h1>
<p class="meta">Versiunea {{ $waiver->document_version }} · amprenta textului: {{ $waiver->document_hash }}</p>
<table>
    <tr><td>Participant</td><td>{{ $waiver->participant_name }}</td></tr>
    <tr><td>Data nașterii</td><td>{{ \Carbon\CarbonImmutable::parse($waiver->participant_birth_date)->format('d.m.Y') }}{{ $waiver->is_minor ? ' (minor)' : '' }}</td></tr>
    <tr><td>Rezervare</td><td>{{ $waiver->booking?->code ?? '—' }}{{ $waiver->booking?->starts_at ? ' · '.\App\Support\LocalTime::format($waiver->booking->starts_at, 'd.m.Y H:i') : '' }}</td></tr>
    @if ($waiver->is_minor)
        <tr><td>Părinte / reprezentant legal</td><td>{{ $waiver->guardian_name }}{{ $waiver->guardian_relationship ? ' ('.$waiver->guardian_relationship.')' : '' }}{{ $waiver->guardian_phone ? ' · '.$waiver->guardian_phone : '' }}</td></tr>
    @endif
    <tr><td>Semnatar</td><td>{{ $waiver->signer_name }} — {{ $waiver->signer_role === 'guardian' ? 'părinte / reprezentant legal' : 'participant major, în nume propriu' }}</td></tr>
    <tr><td>Semnată</td><td>{{ \App\Support\LocalTime::format($waiver->signed_at, 'd.m.Y H:i') }} · {{ $waiver->source === 'reception' ? 'la recepție (cod QR)' : 'online (link rezervare)' }}</td></tr>
    <tr><td>Opțiuni</td><td>E-mail marketing: {{ $waiver->marketing_email ? 'da' : 'nu' }} · Foto/video: {{ $waiver->photo_consent ? 'da' : 'nu' }}</td></tr>
    <tr><td>Verificată la recepție</td><td>{{ $waiver->verified_at ? \App\Support\LocalTime::format($waiver->verified_at, 'd.m.Y H:i') : 'nu' }}</td></tr>
    <tr><td>Semnătura</td><td class="sig"><img src="{{ $waiver->signature }}" alt="Semnătura"></td></tr>
</table>
<h2>Textul acceptat</h2>
<div>{!! $body !!}</div>
</body>
</html>
