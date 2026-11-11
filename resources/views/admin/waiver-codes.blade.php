<!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex">
<title>Coduri pentru tabletă</title>
<style>
  @page { size: A4; margin: 10mm; }
  body { font-family: system-ui, sans-serif; color: #0a1033; margin: 0; }
  .bar { margin: 6mm; display: flex; gap: 4mm; align-items: center; } @media print { .bar { display: none; } }
  .sheet { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0; max-width: 190mm; margin: 0 auto; }
  .slip { border: 1px dashed #9aa0c0; padding: 6mm 7mm; text-align: center; break-inside: avoid; min-height: 62mm; display: grid; align-content: center; gap: 1.5mm; }
  .slip img { height: 13mm; margin: 0 auto 1mm; }
  .slip .who { font-size: 9pt; color: #555; text-transform: uppercase; letter-spacing: .08em; }
  .slip .code { font-size: 30pt; font-weight: 800; letter-spacing: .12em; font-variant-numeric: tabular-nums; }
  .slip .how { font-size: 10pt; } .slip .how em { color: #555; }
  .slip .exp { font-size: 8.5pt; color: #555; }
  .empty { text-align: center; margin: 20mm; font-size: 13pt; }
</style>
</head>
<body>
<div class="bar"><button onclick="window.print()">Tipărește</button><span>Tableta: {{ $tabletUrl }}</span></div>
@if ($codes->isEmpty())
    <p class="empty">Nu există coduri nefolosite. Generează coduri din check-in, din rezervare sau din sesiunea de joc.</p>
@else
    <div class="sheet">
        @foreach ($codes as $code)
            <div class="slip">
                <img src="{{ asset('media/brand/aw-logo-stacked-cluj-dark.svg') }}" alt="Another World Cluj">
                <div class="who">Jucător {{ $code->position }}@if ($booking) · {{ $booking->code }}@endif</div>
                <div class="code">{{ $code->formatted() }}</div>
                <div class="how">Tastează codul pe tableta de la recepție și semnează declarația.<br><em>Enter this code on the front-desk tablet to sign the agreement.</em></div>
                <div class="exp">Valabil o singură dată, până la {{ \App\Support\LocalTime::format($code->expires_at, 'H:i') }}.</div>
            </div>
        @endforeach
    </div>
@endif
</body>
</html>
